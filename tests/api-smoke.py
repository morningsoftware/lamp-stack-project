"""Exercises validation and filtered pagination against an isolated CI database."""
import json, urllib.request, urllib.error
BASE = 'http://127.0.0.1:8088/api/index.php'
token = None

def request(method, path, data=None, expected=200):
    """Send a request and require the expected HTTP status without logging secrets."""
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(BASE + path, headers=headers, method=method,
                                 data=json.dumps(data).encode() if data is not None else None)
    try:
        response = urllib.request.urlopen(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    assert response.status == expected, (method, path, response.status, expected)
    return json.load(response).get('data')

user = request('POST', '/auth/register', {'login': 'ci_audit', 'email': 'ci@example.com',
    'password': 'CI-only-disposable-password', 'firstName': 'CI', 'lastName': 'Audit'}, 201)
token = user['token']
uid = user['userid']
request('PUT', f'/profiles/{uid}', {'firstName': 'a' * 51}, 400)
request('PUT', f'/profiles/{uid}', {'firstName': []}, 400)
request('PUT', f'/profiles/{uid}', {'firstName': 'é' * 50})
request('POST', f'/profiles/{uid}/social_links', {'platform': 'test', 'url': 'javascript:void(0)'}, 400)
request('POST', f'/profiles/{uid}/social_links', {'platform': 'test', 'url': 'https://example.com'}, 201)
request('POST', '/auth/register', {'login': 'bad"handle', 'email': 'bad@example.com', 'password': 'CI-only-password'}, 400)
org = request('POST', '/organizations', {'name': 'CI audit'}, 201)
for i in range(27):
    request('POST', '/roles', {'organizationid': org['organizationid'], 'name': 'CI role ' + str(i),
        'skills': 'PHP' if i == 0 else ('PHP,JavaScript' if i == 1 else 'JavaScript')}, 201)
page1 = request('GET', '/roles?q=CI%20role&limit=25&offset=0')
page2 = request('GET', '/roles?q=CI%20role&limit=25&offset=24')
assert len(page1) == 25 and len(page2) == 3
assert page1[24]['roleid'] == page2[0]['roleid']
assert request('GET', '/roles?q=CI%20role&skill=nonexistent-ci-skill&limit=25') == []
print('PASS: live API validation, safe URLs, role pagination and server skill filter')

assert len(request('GET', '/roles?q=CI%20role&skill=PHP&limit=1')) == 1
assert len(request('GET', '/roles?q=CI%20role&skill=PHP')) == 2
assert len(request('GET', '/roles?q=CI%20role&skill=PHP,JavaScript&skillMode=all')) == 1
assert len(request('GET', '/roles?q=CI%20role&skill=PHP,JavaScript&skillMode=any')) == 27
print('PASS: role skill any/all filters run before pagination')
