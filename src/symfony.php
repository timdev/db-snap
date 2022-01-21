#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Aws\Exception\MultipartUploadException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\MultipartUploader;
use Aws\S3\S3MultiRegionClient;
use GuzzleHttp\Exception\ClientException as GuzzleClientException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

function validateOpts(array $o): array
{
    if (empty($o['db'])){
        throw new \InvalidArgumentException('Missing required option --db');
    }

    if (empty($o['s3bucket'])){
        throw new \InvalidArgumentException('Missing required option --s3bucket');
    }

    $o['sweep-days'] = (int) $o['sweep-days'];

    // local snapshot dir
    $localDir = $o['local-dir'];
    if (!is_dir($localDir) && !mkdir($localDir) && !is_dir($localDir)) {
        throw new \InvalidArgumentException("Failed creating local snapshot dir: {$localDir}");
    }
    if (!is_writable($localDir)) {
        throw new \InvalidArgumentException("Local snapshot directory ({$localDir}) is not writable.");
    }
    $o['local-dir'] = realpath($localDir);

    // user-specified hostname component.
    $hostname = $o['hostname'];
    // if no user-specified value, use dbhost if it isn't localhost
    if (empty($hostname) && !in_array($o['db-host'], ['localhost', '127.0.0.1'])) {
        $hostname = $o['db-host'];
    }
    // if still no hostname, and we're dumping ove SSH, use whatever the ssh-host thinks its own hostname is.
    if (empty($hostname) && $o['ssh-host']){
        $hostname = trim(shell_exec("ssh -C '{$o['ssh-host']}' hostname"));
    }
    // No user-override, no remote database server, no ssh-host, so use local machine hostname.
    if (empty($hostname)) {
        $hostname = gethostname();
    }
    $o['hostname'] = $hostname;

//    var_dump(empty(o['aws-access-key']) , empty(o['aws-secret-key']));die();
    if (empty($o['aws-access-key']) !== empty($o['aws-secret-key'])) {
        throw new \InvalidArgumentException('Must specify both --aws-access-key and --aws-secret-key or neither.');
    }

    return $o;
}

function mysqldump_command(string $db, ?string $host = null, ?string $user = null, ?string $pass = null, string $defaultsFile = null)
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

    if (!empty($defaultsFile)){
        $args .= ' --defaults-extra-file=' . escapeshellarg($defaultsFile);
    }

    $args .= " --single-transaction --triggers --databases " . escapeshellarg($db);
    return 'mysqldump ' . $args;
}


function human_filesize(int $bytes, int $decimals = 2)
{
    $sz = 'BKMGTP';
    $factor = (int) floor((strlen((string) $bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / (1024 ** $factor)) . ($sz[$factor] ?: '');
}

function makeS3Client(array $opts): S3MultiRegionClient
{
    $s3Opts = [
        'version' => 'latest'
    ];
    if ($opts['aws-access-key'] && $opts['aws-secret-key']){
        $s3Opts['credentials'] = [
            'key' => $opts['aws-access-key'],
            'secret' => $opts['aws-secret-key']
        ];
    }elseif($opts['aws-profile']){
        $s3Opts['profile'] = $opts['aws-profile'];
    }

    return new S3MultiRegionClient($s3Opts);
}

function upload(string $localPath, string $bucket, string $key, S3MultiRegionClient $s3Client, OutputInterface $out): void
{
    $uploader = new MultipartUploader($s3Client, $localPath, compact('bucket', 'key'));
    $source = $localPath;
    $filesize = filesize($localPath);
    $out->write('Uploading ' . human_filesize($filesize) . ' to ' . $bucket . '/' . $key . ' ... ');
    $startTime = microtime(true);
    do {
        try {
            $result = $uploader->upload();
        } catch (MultipartUploadException $e) {

            if ($e->getPrevious() instanceof S3Exception
                && $e->getPrevious()->getPrevious() instanceof GuzzleClientException
                && $e->getPrevious()->getPrevious()->getCode() === 403) {
                throw new \RuntimeException('Upload Failed with 403 Forbidden.  Your AWS credentials are probably wrong.');
            }

            $out->writeln("Upload failed [{$e->getMessage()}].  Retrying.");
            $uploader = new MultipartUploader($s3Client, $source, [
                'state' => $e->getState(),
            ]);

        } catch (\LogicException $e) {
            throw new \RuntimeException("Upload failed: [{$e->getMessage()}].  Fatal.");
        }
    } while (!isset($result));

    $elapsedSecs = ((microtime(true) - $startTime));
    $throughput = ( ($filesize/(1024*1024)) / $elapsedSecs) ;
    $elapsedSecs = round($elapsedSecs, 2);
    $throughput = round($throughput, 2);
    $out->writeln("<info>OK:</info> {$elapsedSecs} seconds @ {$throughput} MB/s");
}

function snapshotName(array $opts): string
{
    /*
    * Determine hostname component for dump file.
    */

    // user-specified hostname component.
    $hostname = $opts['hostname'];

    // if no user-specified value, use dbhost if it isn't localhost
    if (empty($hostname) && !in_array($opts['db-host'], ['localhost', '127.0.0.1'])) {
        $hostname = $opts['db-host'];
    }

    // if still no hostname, and we're dumping ove SSH, use whatever the ssh-host thinks its own hostname is.
    if (empty($hostname) && $opts['ssh-host']) {
        $hostname = trim(shell_exec("ssh -C '{$opts['ssh-host']}' hostname"));
    }

    // No user-override, no remote database server, no ssh-host, so use local machine hostname.
    if (empty($hostname)) {
        $hostname = gethostname();
    }
    $ts = date('Ymd-Hi');
    return "{$hostname}.{$opts['db']}.{$ts}.sql.bz2" . ($opts['gpg-recipient'] ? '.gpg' : '');
}

function main(InputInterface $input, OutputInterface  $output): int {

    $startTime = time();
    $output->writeln('Starting at ' . date('Y-m-d H:i:s', $startTime));
    $opts = $input->getOptions();
    try {
        $opts = validateOpts($opts);
    }catch(\InvalidArgumentException $e){
        $output->writeln('<error>' . $e->getMessage() . '</error>');
        return 1;
    }

    try {
        // filename of snapshot file.
        $dumpName = snapshotName($opts);

        $gpgCmd = '';
        if ($opts['gpg-recipient']) {
            $gpgCmd = '| gpg --encrypt --recipient ' . escapeshellarg($opts['gpg-recipient']);
        }

        // local pathname of snapshot file.
        $localSnapshotPathname = "{$opts['local-dir']}/{$dumpName}";
        // ... as an escaped shell argument.
        $localSnapshotPathnameArg = escapeshellarg($localSnapshotPathname);

        $dumpCmd = mysqldump_command($opts['db'], $opts['db-host'], $opts['db-user'], $opts['db-password']);

        if ($opts['ssh-host']){
            $dumpCmd = "ssh {$opts['ssh-host']} {$dumpCmd}";
        }

        $cmd = <<<TXT
            set -e -o pipefail && \
            {$dumpCmd}\
                | bzip2 -c {$gpgCmd} \
                > {$localSnapshotPathnameArg}  
            TXT;
        $cmd = "sh -c '{$cmd}'";


        $cmd .= ' 2>&1';

        // Run the shell command.
        $output->writeln("<info>Running:</info> {$cmd}", $output::VERBOSITY_VERY_VERBOSE);


        $cmdOutput = [];
        $rc = null;
        $output->write("Preparing backup to: {$localSnapshotPathname} ... ");
        exec($cmd, $cmdOutput, $rc);
        if ($rc !== 0) {
            $output->writeln("<error>FAILED</error>");
            $output->writeln('<error>' . implode("\n", $cmdOutput) . '</error>');
            exit(1);
        }

        // Report result of local operation
        $size = human_filesize(filesize($localSnapshotPathname));
        $elapsed = (time() - $startTime) . ' seconds';
        $output->writeln("<info>OK:</info> ($size) ($elapsed)");


        //
        try {
            upload($localSnapshotPathname, $opts['s3bucket'], $opts['s3prefix'] . '/' . $dumpName, makeS3Client($opts), $output);
        }catch(\Exception $e){
            $output->writeln("<error>FAILED</error>");
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            exit(1);
        }

        $output->writeln('Finished at ' . date('Y-m-d H:i:s'));
    }catch(\Throwable $e){
        $output->writeln('<error>' . $e->getMessage() . '</error>');
        return 1;
    }
    return 0;
};

$description = <<<TXT
A MySQL Snapshot Utility

Drives `mysqldump`, optionally over SSH, to snapshot a MySQL database, storing
the (bzip2-compressed, and optionally encrypted) snapshot in an S3 bucket. 

If your environment is set up with ~/.my.cnf and a your default AWS_PROFILE can
write to your S3 bucket, usage can be as simple as:

./db-snap --db=mydb --s3bucket=my-bucket-name

By default, this program will store snapshots in the local filesystem for six
weeks. You can change the retention period by passing an integer with to 
--sweep-days, or pass --delete-local to delete the local copy immediately after
a successul upload to S3.

(More) complete documentation is available at:

https://github.com/timdev/db-snap

TXT;


(new SingleCommandApplication())
    ->setName('db-snap')
    ->setVersion('2.0.0-dev')
    ->setDescription($description)
    ->addOption('db', null, InputOption::VALUE_REQUIRED, 'REQUIRED: The name of the database to snapshot.')
    ->addOption('s3bucket', null, InputOption::VALUE_REQUIRED, 'REQUIRED: The name of the bucket to store the snapshot in.')
    ->addOption('s3prefix', null, InputOption::VALUE_REQUIRED, 'The prefix to use when storing the snapshot in S3.', 'db_backups')
    ->addOption('hostname', null, InputOption::VALUE_REQUIRED, 'If Present..')
    ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Database hostname (the \'-h\' option to mysqldump)', 'localhost')
    ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Database username (the \'-u\' option to mysqldump)')
    ->addOption('db-password', null, InputOption::VALUE_REQUIRED, 'Database password')
    ->addOption('db-defaults-file', null, InputOption::VALUE_REQUIRED, 'Path to a .cnf file containing database credentials')
    ->addOption('aws-region', null, InputOption::VALUE_REQUIRED, 'AWS region to use for S3.', 'us-east-1')
    ->addOption('aws-profile', null, InputOption::VALUE_REQUIRED, 'AWS profile (from ~/.aws/credentials) to use to connect to S3.')
    ->addOption('aws-access-key', null, InputOPtion::VALUE_REQUIRED, 'AWS access key, requires --aws-secret-key')
    ->addOption('aws-secret-key', null, InputOption::VALUE_REQUIRED, 'AWS secret key, requires --aws-access-key')
    ->addOption('ssh-host', null, InputOption::VALUE_REQUIRED, 'SSH to this host to perform dump. ex: example.com or user@example.com')
    ->addOption('local-dir', null, InputOption::VALUE_REQUIRED, 'Local directory for snapshots.', sys_get_temp_dir() . '/db-snaps')
    ->addOption('delete-local', null, InputOption::VALUE_NONE, 'If passed, delete local snapshot immediately after successful upload to S3')
    ->addOption('sweep-days', null, InputOption::VALUE_REQUIRED, 'Delete all files in <local-dir> more than <sweep-days> days old. Default: 42 days.</sweep-days>', '42')
    ->addOption('no-sweep', null, InputOption::VALUE_NONE, 'If passed, do not delete local snapshots.')
    ->addOption('gpg-recipient', null, InputOption::VALUE_REQUIRED, 'GPG recipient to encrypt the snapshot for')
    ->setCode('main')
    ->run();
