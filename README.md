
# Detect and email when Cloudflares IP lists have changes so we can update firewalls etc. 

Uses Mailgun to send the email atm. (TODO: change to support custom SMTP and other notification formats)

Sidenote: This could also be adapted to reload firewalls directly etc. removing the need for the email.

## Install

Copy and then fill out the .env
```
cp .env.example .env
```

## Run

Manually test with:
```
php index.php
```

Or cron for daily @ 6am for example:
```
0 6 * * * php /srv/monitor-cloudflare-ips/index.php
```


