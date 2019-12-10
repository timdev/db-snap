#!/usr/bin/env php
<?php

use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Aws\S3\S3MultiRegionClient;
use League\CLImate\CLImate;

ini_set('date.timezone', 'UTC');

require __DIR__ . '/../vendor/autoload.php';

define('NAME', 'timdev/db-snap');
define('VERSION', '1.0.3');
define('DATE', '2019-12-10');

$cli = new CLImate();
$cli->description(sprintf('%s v%s [%s]', NAME, VERSION, DATE));

if (empty(`which mysqldump`)){
    $cli->to('error')->red('Cannot find `mysqldump` binary.  Check that it is on your $PATH');
    exit(1);
}

if (empty(`which bzip2`)){
    $cli->to('error')->red('Cannot find `bzip2` binary.  Check that it is on your $PATH');
    exit(1);
}

try {
    $cli->arguments->add([
        'help' => [
            'longPrefix' => 'help',
            'prefix' => 'h',
            'description' => 'Print usage information and exit',
            'noValue' => true
        ],
        'version' => [
            'longPrefix' => 'version',
            'prefix' => 'v',
            'description' => 'Print version and exit.',
            'noValue' => true
        ],
        'dbname' => [
            'longPrefix' => 'db',
            'description' => 'The name of the database to back up.',
            'required' => true
        ],
        'bucket' => [
            'longPrefix' => 's3bucket',
            'description' => 'The name of the AWS S3 bucket to store the backups',
            'required' => true
        ],
        'bucketPrefix' => [
            'longPrefix' => 's3prefix',
            'description' => 'The prefix (folder) under which to store the backup in the s3 bucket.  Backup file path will be: <bucket-prefix>/<hostname>/<hostname>.<dbname>.<timestamp>.sql.bz2',
            'defaultValue' => 'db_backups'
        ],
        'hostname' => [
            'longPrefix' => 'hostname',
            'description' => 'If specified, overrides the hostname part of the object path in the bucket.'
        ],
        'dbhost' => [
            'longPrefix' => 'db-host',
            'description' => 'MySQL server to connect to',
            'defaultValue' => 'localhost'
        ],
        'dbuser' => [
            'longPrefix' => 'db-user',
            'description' => 'Database user to connect as.  Defaults to current user.',
        ],
        'dbpass' => [
            'longPrefix' => 'db-password',
            'description' => 'Password to use when connecting to the database.',
        ],
        'awsProfile' => [
            'longPrefix' => 'aws-profile',
            'description' => 'AWS Profile (from ~/.aws/credentials) to use to connect to S3',
            'defaultValue' => null
        ],
        'awsAccessKey' => [
            'longPrefix' => 'aws-access-key-id',
            'description' => 'AWS Access Key'
        ],
        'awsSecretKey' => [
            'longPrefix' => 'aws-secret-access-key',
            'description' => 'AWS Secret Access Key'
        ],
        'awsRegion' => [
            'longPrefix' => 'aws-region',
            'description' => 'AWS region to connect to'
        ],
        'localDir' => [
            'longPrefix' => 'local-dir',
            'description' => 'Local directory for snapshots.',
            'defaultValue' => sys_get_temp_dir() . '/db-snaps'
        ],
        'sshHost' => [
            'longPrefix' => 'ssh-host',
            'description' => 'SSH to this host to perform dump. ie: example.com or user@example.com'
        ],
        'deleteLocal' => [
            'longPrefix' => 'delete-local',
            'description' => 'Delete local snapshot after successful upload to s3.',
            'noValue' => true
        ]
    ]);
} catch (\Exception $e) {
    $cli->to('error')->red($e->getMessage());
    exit(1);
}

/*
 * Handle --help and --version first, since they make otherwise required arguments not required.
 */
if (in_array('-v', $argv, true) || in_array('--version', $argv, true)) {
    $cli->out(VERSION);
    exit(0);
}

if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    $cli->usage();
    exit(0);
}

try {
    $cli->arguments->parse();
} catch (\Exception $e) {
    $cli->to('error')->red($e->getMessage());
    $cli->to('error')->usage();
    exit(1);
}

/*
 * Handle --help and --version
 */
if ($cli->arguments->get('help')) {
    $cli->usage();
    exit(0);
}

if ($cli->arguments->get('version')) {
    $cli->out(sprintf('%s/%s %s', NAME, VERSION, DATE));
    exit(0);
}


/*
 * Extract and validate some arguments.
 */
$args = $cli->arguments->toArray();
$dbname = $args['dbname'];
$bucket = $args['bucket'];

if (empty($args['awsAccessKey']) !== empty($args['awsSecretKey'])) {
    $cli->to('error')->red('Options --aws-access-key-id and --aws-secret-access-key must be specified together');
    $cli->to('error')->usage();
    exit(1);
}


$tmpdir = $args['localDir'];

if (!is_dir($tmpdir) && !mkdir($tmpdir) && !is_dir($tmpdir)) {
    $cli->to('error')->red("Failed creating local temp dir: {$tmpdir}");
    exit(1);
}

if (!is_writable($tmpdir)) {
    $cli->to('error')->to('error')->red("Local temp directory ({$tmpdir}) is not writable.");
    exit(1);
}

/*
 * Determine hostname component for dump file.
 */

// user-specified hostname component.
$hostname = $args['hostname'];

// if no user-specified value, use dbhost if it isn't localhost
if (empty($hostname) && !in_array($args['dbhost'], ['localhost', '127.0.0.1'])) {
    $hostname = $args['dbhost'];
}

// if still no hostname, and we're dumping ove SSH, use whatever the ssh-host thinks it's own hostname is.
if (empty($hostname) && $args['sshHost']){
    $hostname = trim(`ssh -C {$args['sshHost']} hostname`);
}

// No user-override, no remote database server, no ssh-host, so use local machine hostname.
if (empty($hostname)) {
    $hostname = gethostname();
}

/*
 * Build an S3 Client.
 */
$s3Opts = [
    'version' => 'latest'
];

if (! empty($args['awsRegion'])){
    $s3Opts['region'] = $args['awsRegion'];
}

if (!empty($args['awsAccessKey'])) {
    $cli->yellow("Using AWS Key: {$args['awsAccessKey']}.  Consider using profiles instead.");
    $s3Opts['credentials'] = [
        'key' => $args['awsAccessKey'],
        'secret' => $args['awsSecretKey']
    ];
} elseif (!empty($args['awsProfile'])) {
    if ($args['awsProfile'] === 'default') {
        $cli->yellow("Using AWS Profile: {$args['awsProfile']}.  (Use --aws-profile= to use a different profile");
    }
    $s3Opts['profile'] = $args['awsProfile'];
}else{
    $cli->yellow('No credentials or profile specified, relying on environment for AWS authentication.');
}

$s3 = new S3MultiRegionClient($s3Opts);

/*
 * Perform a dump
 */
$startTime = time();
$cli->out('Backup script started at ' . date('c', $startTime));

$dumpName = $hostname . '.' . $dbname . '.' . date('Ymd-Hi') . '.sql';
$tmpfile = "{$tmpdir}/{$dumpName}";


$connArgs = dump_connection_args($args['dbhost'], $args['dbuser'], $args['dbpass']);

// eschews pipes so that if mysqldump fails we get a non-zero exist code.
$cmd = "mysqldump --single-transaction --triggers {$connArgs} ${dbname} > {$tmpfile} && bzip2 {$tmpfile}";

if($args['sshHost']){
    $cmd = "ssh {$args['sshHost']} {$cmd}";
}

$tmpfile .= '.bz2';

$output = [];
$rc = null;
$cli->inline("Preparing backup to: {$tmpfile} ... ");
exec($cmd, $output, $rc);
$size = human_filesize(filesize($tmpfile));

if ($rc !== 0) {
    $cli->to('error')->out('Failed taking snapshot.');
    $cli->to('error')->out(implode("\n", $output));
    exit(1);
}

$cli->out("OK. ($size)");
$cli->out('Local backup took ' . (time() - $startTime) . ' seconds');

/*
 * Push data to S3
 */

$s3key = "{$hostname}/{$dumpName}.bz2";
// Add bucket prefix if not-empty.
if (!empty($args['bucketPrefix'])) {
    $s3key = "{$args['bucketPrefix']}/{$s3key}";
}

$source = fopen($tmpfile, 'rb');

$uploader = new MultipartUploader($s3, $source, ['bucket' => $bucket, 'key' => $s3key]);

$uploadStart = time();
$cli->inline("Uploading to {$s3key}  ... ");
do {
    try {
        $result = $uploader->upload();
    } catch (MultipartUploadException $e) {

        if ($e->getPrevious() instanceof \Aws\S3\Exception\S3Exception && $e->getPrevious()->getPrevious() instanceof GuzzleHttp\Exception\ClientException && $e->getPrevious()->getPrevious()->getCode() === 403) {
            $cli->to('error')->red('Upload Failed with 403 Forbidden.  Your AWS credentials are probably wrong.');
            exit(1);
        }

        $cli->red("Upload failed [{$e->getMessage()}].  Retrying.");
        rewind($source);
        $uploader = new MultipartUploader($s3, $source, [
            'state' => $e->getState(),
        ]);
    } catch (\LogicException $e) {
        $cli->to('error')->out("Upload failed: [{$e->getMessage()}].  Fatal.");
        exit(1);
    } catch (\Aws\Exception\CredentialsException $e) {
        $cli->to('error')->red($e->getMessage());
        exit(1);
    }
} while (!isset($result));

$cli->out('OK');
$cli->out('Upload took ' . (time() - $uploadStart) . ' seconds.');


$cli->inline('Cleaning up local backups older than 6 weeks ... ');
exec("find {$tmpdir} -mtime +42 | xargs rm -f");
$cli->out('Done.');

$endTime = time();
$elapsedTime = $endTime - $startTime;

$cli->out('Backup completed at ' . date('c', $endTime));
$cli->out("Backup took {$elapsedTime} seconds");

if ($cli->arguments->get('deleteLocal')){
    $cli->out('Deleting local snapshot because --delete-local');
    unlink($tmpfile);
}

$cli->table([
    ['Host', $hostname],
    ['Database', $dbname],
    ['Snapshot Size', $size],
    ['S3 Bucket', $bucket],
    ['S3 Object Key', $s3key]
]);
exit(0);


function human_filesize($bytes, $decimals = 2)
{
    $sz = 'BKMGTP';
    $factor = (int)floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ($sz[$factor] ?: '');
}

function dump_connection_args($host = null, $user = null, $pass = null)
{
    $args = '';
    if (!empty($host)) {
        $args .= ' -h ' . escapeshellarg($host);
    }
    if (!empty($user)) {
        $args .= ' -u ' . escapeshellarg($user);
    }
    if (!empty($pass)) {
        $args .= ' -p' . escapeshellarg($pass);
    }
    return $args;
}
