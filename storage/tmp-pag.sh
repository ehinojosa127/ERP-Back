API=http://127.0.0.1:8000/api
JH='-H Content-Type:application/json -H Accept:application/json'
AT=$(curl -s -X POST $API/auth/login $JH -d '{"email":"admin@example.com","password":"password"}' | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['access_token'])")
A="Authorization: Bearer $AT"
meta() { curl -s "$API$1" -H "$A" $JH | python3 -c "
import sys,json
d=json.load(sys.stdin)['data']
print(f\"  page={d['current_page']} per_page={d['per_page']} total={d['total']} last_page={d['last_page']} devueltos={len(d['data'])}\")
if d['data']:
    f=d['data'][0]
    label=f.get('company_name') or ' '.join(x for x in [f.get('name'),f.get('lastname')] if x) or f.get('username')
    print('  primero:', label)
"; }
echo "GET /customers (default)";              meta "/customers"
echo "GET /customers?page=3";                 meta "/customers?page=3"
echo "GET /customers?per_page=5&page=2";      meta "/customers?per_page=5&page=2"
echo "GET /customers?search=Lima";            meta "/customers?search=Lima"
echo "GET /suppliers (default)";              meta "/suppliers"
echo "GET /suppliers?search=SAC";             meta "/suppliers?search=SAC"
echo "GET /users";                            meta "/users"
echo "GET /users?search=admin";               meta "/users?search=admin"
echo "GET /roles";                            meta "/roles"
echo ""
echo "--- validacion de parametros invalidos ---"
for q in "per_page=999" "page=0" "per_page=abc"; do
  printf "  %-16s -> HTTP %s  %s\n" "$q" \
    "$(curl -s -o /tmp/v.json -w '%{http_code}' "$API/customers?$q" -H "$A" $JH)" \
    "$(python3 -c "import json;d=json.load(open('/tmp/v.json'));print(list(d.get('errors',{}).values())[0][0] if 'errors' in d else '')")"
done
echo ""
echo "--- catalogo de roles (sin paginar) ---"
curl -s $API/roles/catalog -H "$A" $JH | python3 -c "import sys,json;print(' ',json.load(sys.stdin)['data'])"
