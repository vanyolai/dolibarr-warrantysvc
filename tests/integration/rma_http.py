"""Exercise native login, card forms, CSRF and permissions via loopback HTTP."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:18080'
CARD = '/custom/warrantysvc/supplier_rma_card.php'
HERE = Path(__file__).resolve().parent


def check(ok, description):
    if not ok:
        raise AssertionError(description)
    print('PASS: ' + description, flush=True)


def fixture(*args):
    output = subprocess.check_output(['php', str(HERE / 'rma_http_fixture.php'), *map(str, args)], text=True, timeout=15)
    return json.loads(next(line[5:] for line in output.splitlines() if line.startswith('JSON:')))


def request(opener, path, data=None):
    encoded = None if data is None else urllib.parse.urlencode(data).encode()
    try:
        with opener.open(BASE + path, encoded, timeout=15) as response:
            html = response.read().decode('utf-8')
            status = response.status
    except urllib.error.HTTPError as error:
        html, status = error.read().decode('utf-8'), error.code
    check('Fatal error' not in html and 'Parse error' not in html, 'HTTP request has no PHP fatal error')
    return status, html


def token(html):
    match = re.search(r'name="anti-csrf-newtoken" content="([^"]+)"', html)
    if not match:
        match = re.search(r'name="token"[^>]*value="([^"]+)"', html)
    check(bool(match), 'Native CSRF token rendered')
    return match.group(1)


def login(role):
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    _, html = request(opener, '/index.php')
    _, html = request(opener, '/index.php', dict(token=token(html), username='fixture-' + role,
        password='Fixture-Only-23!', actionlogin='login', loginfunction='loginfunction'))
    check('id="login"' not in html and 'name="username"' not in html, 'Native login succeeds for ' + role)
    return opener


def mutation(opener, rid, action, **values):
    _, html = request(opener, '/index.php')
    payload = dict(token=token(html), id=rid, action=action, revision=fixture('snapshot', rid)['row']['revision'])
    payload.update(values)
    return request(opener, CARD + '?id=' + str(rid), payload)


if os.environ.get('WARRANTYSVC_TEST_ALLOW') != 'yes':
    raise RuntimeError('Explicit disposable-fixture opt-in required')
ids = fixture('prepare')
for attempt in range(100):
    try:
        urllib.request.urlopen(BASE + '/index.php', timeout=1).close()
        break
    except urllib.error.URLError:
        time.sleep(0.05)
else:
    raise RuntimeError('HTTP fixture did not become ready')
admin, reader, writer, deleter, noaccess = [login(role) for role in ('admin', 'reader', 'writer', 'deleter', 'noaccess')]
rid = ids['editable']
initial = fixture('snapshot', rid)
for action in ('update', 'setstatus', 'update_business_date', 'confirm_rollback', 'confirm_delete'):
    request(admin, CARD + '?' + urllib.parse.urlencode(dict(id=rid, action=action, newstatus='authorized', confirm='yes')))
    check(fixture('snapshot', rid) == initial, 'GET cannot mutate ' + action)
for role, opener in (('reader', reader), ('deleter', deleter), ('noaccess', noaccess)):
    for action in ('update', 'setstatus', 'update_business_date', 'confirm_rollback'):
        mutation(opener, rid, action, diagnosis='Unauthorized', newstatus='authorized', confirm='yes',
            field='date_request', business_date_day=1, business_date_month=10, business_date_year=2026)
        check(fixture('snapshot', rid) == initial, role + ' cannot mutate ' + action)

# Missing/forged token must be rejected by main.inc.php before card actions.
for csrf in (None, 'forged-token'):
    payload = dict(id=rid, action='setstatus', newstatus='authorized', revision=1)
    if csrf is not None:
        payload['token'] = csrf
    request(admin, CARD + '?id=' + str(rid), payload)
    check(fixture('snapshot', rid) == initial, 'Missing/forged CSRF leaves record and history unchanged')

status, html = request(writer, CARD + '?id=' + str(rid) + '&action=edit')
check(status == 200 and 'name="revision" value="1"' in html, 'Edit form carries persisted revision')
edit = dict(fk_svc_request=os.environ['WARRANTYSVC_TEST_REQUEST_ID'],
    fk_soc_supplier=os.environ['WARRANTYSVC_TEST_SUPPLIER_ID'], fk_product=os.environ['WARRANTYSVC_TEST_PRODUCT_ID'],
    qty=1, serial_number=initial['row']['serial_number'], problem_description=initial['row']['problem_description'],
    diagnosis='HTTP winner', revision=1)
mutation(writer, rid, 'update', **edit)
current = fixture('snapshot', rid)
check(current['row']['revision'] == 2 and current['row']['diagnosis'] == 'HTTP winner', 'Authorized native HTTP edit commits')
edit['diagnosis'] = 'Stale HTTP overwrite'
mutation(admin, rid, 'update', **edit)
check(fixture('snapshot', rid) == current, 'Stale native HTTP edit cannot overwrite winner')

mutation(writer, rid, 'setstatus', newstatus='authorized')
current = fixture('snapshot', rid)
check(current['row']['status'] == 'authorized' and current['row']['revision'] == 3, 'Authorized HTTP status transition')
_, html = request(writer, CARD + '?id=' + str(rid) + '&action=rollback')
check('confirm_rollback' in html and 'revision=3' in html and 'POST' in html, 'Native rollback confirmation submits POST with revision')
mutation(writer, rid, 'confirm_rollback', confirm='yes')
current = fixture('snapshot', rid)
check(current['row']['status'] == 'draft' and current['row']['revision'] == 4, 'Native HTTP rollback confirmation works')

mutation(writer, rid, 'update_business_date', field='date_request', business_date_day=1,
    business_date_month=10, business_date_year=2026, date_reason='HTTP correction')
current = fixture('snapshot', rid)
check(current['row']['revision'] == 5 and current['row']['date_request'].startswith('2026-10-01'), 'Native HTTP date correction commits')
check(current['logs'][0]['date_event'] == initial['logs'][0]['date_event'], 'HTTP date correction preserves recorded timestamp')

for opener in (reader, writer):
    before = fixture('snapshot', ids['deletable'])
    mutation(opener, ids['deletable'], 'confirm_delete', confirm='yes')
    check(fixture('snapshot', ids['deletable']) == before, 'Role without delete permission cannot delete')
_, html = request(deleter, CARD + '?id=' + str(ids['deletable']) + '&action=delete')
check('confirm_delete' in html and 'revision=1' in html and 'POST' in html, 'Native delete confirmation submits POST with revision')
mutation(deleter, ids['deletable'], 'confirm_delete', confirm='yes')
check(fixture('snapshot', ids['deletable']) == dict(row=None, logs=[]), 'Read+delete role can delete pristine draft and CREATE together')

before = fixture('snapshot', ids['retained'])
mutation(deleter, ids['retained'], 'confirm_delete', confirm='yes')
check(fixture('snapshot', ids['retained']) == before, 'Delete permission cannot bypass historical retention')
mutation(writer, ids['stale_delete'], 'setstatus', newstatus='authorized')
before = fixture('snapshot', ids['stale_delete'])
mutation(deleter, ids['stale_delete'], 'confirm_delete', confirm='yes', revision=1)
check(fixture('snapshot', ids['stale_delete']) == before, 'Stale HTTP delete confirmation rejected')

before = fixture('snapshot', ids['foreign'])
mutation(admin, ids['foreign'], 'setstatus', newstatus='authorized')
check(fixture('snapshot', ids['foreign']) == before, 'Foreign-entity direct request cannot mutate RMA')
print('Authenticated HTTP/CSRF/permission regression passed', flush=True)
