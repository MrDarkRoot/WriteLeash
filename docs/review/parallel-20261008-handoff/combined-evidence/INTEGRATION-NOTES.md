# Combined-candidate integration notes (B -> A -> C -> D)

## 1. Conflict resolution — wordpress/tests/free/in-container.sh

Both A and B appended one line after `php /opt/tests/free/variations.php` in the same hunk.
Resolution: additive union (both test entrypoints kept).

### Baseline (c48e307)
#!/bin/sh
set -eu
php /opt/tests/free/unit.php
php /opt/tests/free/sale-price.php
php /opt/tests/free/variations.php
for host in mysql mariadb; do
  site="/tmp/wl107-$host"

### B lane (work/209-public-cache)
#!/bin/sh
set -eu
php /opt/tests/free/unit.php
php /opt/tests/free/sale-price.php
php /opt/tests/free/variations.php
php /opt/tests/free/cache-public.php
for host in mysql mariadb; do

### A lane (work/206-selection)
#!/bin/sh
set -eu
php /opt/tests/free/unit.php
php /opt/tests/free/sale-price.php
php /opt/tests/free/variations.php
php /opt/tests/free/selection-count.php
for host in mysql mariadb; do

### Combined (resolved)
#!/bin/sh
set -eu
php /opt/tests/free/unit.php
php /opt/tests/free/sale-price.php
php /opt/tests/free/variations.php
php /opt/tests/free/cache-public.php
php /opt/tests/free/selection-count.php
for host in mysql mariadb; do
