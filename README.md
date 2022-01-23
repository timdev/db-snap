# timdev/db-snap

## What is this?

It's (yet another) database snapshot script.  It dumps your (mysql) database and
sticks the dump in an S3 bucket. It's implemented as a PHP script, and might be
useful for folks who work in PHP.

## Features

* Store bzip2-compressed snapshots of databases in S3.
* Optionally encrypt the snapshots with a GPP public key.
* Can operate over ssh.
    * No need to open database ports on the server.
    * Connect to a bastion host and pull data from other hosts on private network.
* Cron Friendly
    * Regular output to STDOUT, errors/warnings to STDERR.
        * Write cron jobs to direct STDOUT someplace like a log file 
          (`... >> /var/log/db-snap.log`)
        * Configure cron to mail job output to you (via MAILTO) -- you'll only 
          get email when something goes wrong.
    * CLI options are the entire interface

## Warning/Disclaimer/Guarantee

I wrote this to meet my own needs, and it hasn't been tested beyond making it
work for me.  Use it at your own risk. It might destroy your computer, network,
or the known universe.  It's guaranteed to have at least a few bugs.

You've been warned.  
      
## Installation

```bash
$ git clone https://github.com/timdev/db-snap.git
$ cd db-snap
$ composer install
```

## How To

A minimal invocation looks like this:

```bash
./bin/db-snap exampledb my-bucket`
````

Which will work fine assuming:

* Your default credentials in ~/.aws/credentials can write to
  `my-bucket`
* You are able to connect to `exampledb` without providing any parameters (that 
  is, `mysql exampledb` works from your shell).

A more complicated invocation might be something like:

```bash
./bin/db-snap \
  --s3-region=us-west-2 \
  --ssh-host=bastion.exmaple.com \
  --db-defaults-file=/etc/db-snap/exampledb.cnf \
  --local-dir=/home/backups/db-snap \
  --gpg-recipient=backups@example.com \
  exampledb my-bucket
```

Such an invocation would:

* Connect to `bastion.example.com` via ssh and invoke `mysqldump` using the 
  connection info in `/etc/db-snap/exampledb.cnf` on that host. 
* Stream the mysqldump output back to the local machine, piping it through 
  `bzip2` and `gpg --encrypt ...`, and writing the output to 
 `/home/backups/db-snap/bastion.example.com.exampledb.sql.bz2.gpg`. 
* Upload the backup file to S3 via the `us-west-2` endpoint.

## More options
 
There are a bunch of optional CLI args available. 

 
## Snapshot Storage

Snapshots are stored locally in the directory given by `--local-dir` (defaulting
to the system's tempdir). Using --local-dir is recommended, so your system 
doesn't automatically clean up local snapshots.

By default, the script will remove all files from `--local-dir` that are older
than 42 days (six weeks). You can change the retention period by providing a
positive integer for `--sweep-days`. If you don't want to delete any files, you
can pass the `--no-sweep` flag.

Alternatively, you can pass `--delete-local`, which will remove the local copy
of the current snapshot as soon as it is successfully uploaded to S3.

Snapshots are named by composing the host-name, database name, and date:
`<hostname>.<dbname>.<datestamp>.sql.bz2[.gpg]`. For example, if your database 
host is `db01.example.com`, and your database is `customers`, the snapshot 
file will be `db01.example.com.customers.20180510-2015.sql.bz2` (for a 
snapshot taken at 20:15 UTC on May 5th, 2018).

The `<hostname>` segment is determined as follows:
* The value passed via the `--hostname` option, else
* The value of `--db-host`, unless it is `localhost` or `127.0.0.1`, which case
* The hostname of the machine where `mysqldump` is being run. (Either the 
  machine where `db-snap` is running, or the machine specified by `--ssh-host`)
* 
Snapshots are stored in the bucket specified by `--s3bucket`, in a directory
specified by `--s3prefix` (default: `db-snap`) This prefix can be changed using
the `--s3prefix` option.  If you want your snapshots stored in the root of the
bucket, simply specify an empty prefix (ie: `--s3prefix='''`)

## Dump over SSH

You can pull a dump from a remote server by passing the `--ssh-host=` option. 
db-snap will invoke the dump script (mysqldump) on the remote host, and stream
the output to a local file.  Examples:

 * `--ssh-host=example.com` -- Connect to example.com
 * `--ssh-host=alice@example.com` -- Connect to example.com as user `alice`
 
Notes:
 
 * No password support. You must be able to connect non-interactively (ie: ssh 
   keys).
 * Be careful about your ForwardAgent setup while testing.
 
This feature allows you to set up a central backup server that connects to 
various hosts to snapshot databases.  


## Best Practices

Generally, you should try to minimize the number of options you're using. 

For example, in many cases, you don't need to provide any AWS credentials. 
Instead, you can rely on the AWS SDK's default behaviors such as instance 
profiles (as when running in EC2), or having profiles set up in 
`~/.aws/credentials`, or having credentials stored in environment variables. 
Using those methods helps you keep your credentials secured.

For MySQL, you should avoid using `--db-pass`. Instead, either rely on a 
properly configured `~/.my.cnf` file, or use `--db-defaults-file` to specify a
custom defaults file on the host where mysqldump will be executed.

## Example Usage

Coming soon.

## To-Do

There are plenty of things that could be done to improve/extend this:

- [ ] Add some usage examples above.
- [ ] Add support for postgres.
- [ ] Allow S3 object paths to be constructed using string templates.
- [x] Add support for pulling dumps through an SSH tunnel.
- [X] Make local snapshot retention period (currently six weeks) configurable via an option.
- [ ] Figure out how to package this as a .phar
