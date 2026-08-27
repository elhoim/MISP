#!/usr/bin/env bash
# Run the live test suite against a configured MISP instance.
#
# Individual suite failures do not abort the run: the goal is to exercise as
# much of the application as possible and then report coverage. Suite results
# are printed so a regression is still visible.
set -uo pipefail

WORKSPACE="${GITHUB_WORKSPACE:-$(pwd)}"
HOST="${HOST:-127.0.0.1}"
AUTH="${AUTH:-$(cat "$WORKSPACE/key.txt")}"

cd "$WORKSPACE"

python3 -m virtualenv -p python3 ./venv
# shellcheck disable=SC1091
. ./venv/bin/activate
pip install -q -r requirements.txt
pip install -q pytest lxml ./PyMISP

# Only now does the interpreter exist, so this is where MISP can be pointed
# at it - setting it during install is rejected as "Binary file not
# executable".
sudo -u www-data app/Console/cake Admin setSetting "MISP.python_bin" "${WORKSPACE}/venv/bin/python"

cat > PyMISP/tests/keys.py <<KEYS
url = "http://${HOST}"
key = "${AUTH}"
KEYS
cp PyMISP/tests/keys.py tests/keys.py
cp PyMISP/tests/keys.py PyMISP/keys.py

export HOST AUTH
export PYTHONPATH="${WORKSPACE}/tests"
export MISP_ROOT="${WORKSPACE}"

status=0

echo "::group::curl_tests_GH.sh"
( cd tests && bash ./curl_tests_GH.sh "$AUTH" "$HOST" ) || status=1
echo "::endgroup::"

# PyMISP's suite runs FIRST, exactly as main.yml orders it, and the order is
# load-bearing: its setUpClass and tests/testlive_comprehensive_local.py's both
# create an organisation literally named 'Test Org'. PyMISP's tears its own
# down, the local one does not - so PyMISP first leaves a clean table for the
# local suite, while local first makes add_organisation return
#   {'errors': ...}  -> cls.test_org is a dict -> AttributeError at
#   testlive_comprehensive.py:96 (user.org_id = cls.test_org.id)
# and every one of its 85 tests errors in setUpClass in about seven seconds.
# That silent loss is most of the gap between coverage measured here and
# coverage measured on a developer's machine.
# testlive_comprehensive.py clones this itself at import time if it is absent,
# but doing it here makes the dependency explicit and shallow (main.yml checks
# the same repository out as a workflow step). Its binary samples are what the
# attachment and file-object tests read.
if [ ! -d PyMISP/tests/viper-test-files ]; then
    git clone --depth 1 https://github.com/viper-framework/viper-test-files.git \
        PyMISP/tests/viper-test-files || true
fi

echo "::group::PyMISP testlive_comprehensive"
( cd PyMISP && python -m pytest -q --no-header tests/testlive_comprehensive.py ) || status=1
echo "::endgroup::"

for suite in testlive_comprehensive_local testlive_security testlive_event_addtag \
             testlive_event_mass_actions testlive_event_templates testlive_collection_sync \
             testlive_dashboards testlive_workflows testlive_export_formats testlive_console; do
    echo "::group::${suite}"
    ( cd tests && python "${suite}.py" -v ) || status=1
    echo "::endgroup::"
done

deactivate
echo "live suite finished (aggregate status ${status}); coverage captures: $(ls "${MISP_COV_DIR:-/cov}"/*.json 2>/dev/null | wc -l)"
exit 0
