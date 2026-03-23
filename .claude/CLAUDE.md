# vektra-moodle

@../.s2s/CONTEXT.md

## Tech Stack

- PHP
- Frameworks: Moodle 5.1+

## Shell script conventions

- Arguments with values: always `--key="value"` (not `--key=value`, not `"--key=value"`)
- Quote all values, including literals: `--dataroot="/var/moodledata"` not `--dataroot=/var/moodledata`
- Variables in quotes: `--dbhost="${DB_HOST}"` not `--dbhost=$DB_HOST`
- Boolean flags stay bare: `--non-interactive`, `--agree-license`

## Plugin deployment

After changing `version.php`, run the Moodle upgrade CLI to register the new
version (the bind mount makes files visible immediately, but Moodle only checks
the version when admin visits the home page or this command is run):

```bash
docker exec vektra-moodle php /var/www/html/admin/cli/upgrade.php --non-interactive
```

## Spec2Ship Commands

- `/s2s:specs` - Define requirements via roundtable
- `/s2s:design` - Design architecture via roundtable
- `/s2s:plan --new` - Create implementation plan
- `/s2s:brainstorm` - Creative ideation session
