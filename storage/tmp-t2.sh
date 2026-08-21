API=http://127.0.0.1:8000/api
JH='-H Content-Type:application/json -H Accept:application/json'
TOK=$(cat storage/tmp-token.txt); JTI=$(cat storage/tmp-jti.txt)
A="Authorization: Bearer $TOK"
code() { printf "%-52s %s\n" "$1" "$2"; }
code "GET  /auth/me" "$(curl -s -o /tmp/me.json -w '%{http_code}' $API/auth/me -H "$A" $JH)"
echo "     perfil -> $(python3 -c "import json;print(json.load(open('/tmp/me.json'))['data'])")"
code "GET  /users (auth:api)" "$(curl -s -o /dev/null -w '%{http_code}' $API/users -H "$A" $JH)"
code "POST /auth/register (middleware permission:2)" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $API/auth/register -H "$A" $JH -d '{"username":"permcheck","email":"permcheck@example.com","password":"password123","password_confirmation":"password123","role_id":2}')"

echo ""
echo "--- login como 'user' (rol sin permisos) y probar middleware ---"
UTOK=$(curl -s -X POST $API/auth/login $JH -d '{"email":"user@example.com","password":"password"}' | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['access_token'])")
python3 -c "
import base64,json
p='$UTOK'.split('.')[1]; p+='='*(-len(p)%4)
d=json.loads(base64.urlsafe_b64decode(p))
print('     bitmask del usuario sin permisos ->', repr(d['permissions']))"
code "POST /auth/register como 'user' (espera 403)" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $API/auth/register -H "Authorization: Bearer $UTOK" $JH -d '{"username":"x1","email":"x1@example.com","password":"password123","password_confirmation":"password123","role_id":2}')"

echo ""
echo "--- logout de la sesion actual ---"
code "POST /auth/logout" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $API/auth/logout -H "$A" $JH -d "{\"refresh_token\":\"ignored\"}")"
