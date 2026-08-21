API=http://127.0.0.1:8000/api
JH='-H Content-Type:application/json -H Accept:application/json'
RES=$(curl -s -X POST $API/auth/login $JH -d '{"email":"admin@example.com","password":"password"}')
echo "=== respuesta de login ==="
echo "$RES" | python3 -c "
import sys,json,base64
r=json.load(sys.stdin)
print('message:', r['message'])
print('user   :', json.dumps(r['data']['user'], ensure_ascii=False))
tok=r['data']['access_token']
payload=tok.split('.')[1]
payload += '='*(-len(payload)%4)
p=json.loads(base64.urlsafe_b64decode(payload))
print()
print('=== payload del access token ===')
print(json.dumps(p, indent=2, ensure_ascii=False))
print()
print('jti         :', p['jti'])
print('permissions :', p['permissions'], '(bitmask hex)')
mask=int(p['permissions'],16)
ids=[i for i in range(mask.bit_length()+1) if mask>>i & 1]
print('decodificado:', ids)
open('storage/tmp-jti.txt','w').write(p['jti'])
open('storage/tmp-token.txt','w').write(tok)
"
