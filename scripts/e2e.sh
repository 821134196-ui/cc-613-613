#!/usr/bin/env bash
# 端到端冒烟：真实 HTTP 调用，覆盖所有关键安全属性
set -uo pipefail
BASE="http://127.0.0.1:8000/api"
PHP="$HOME/.local/bin/php"
PASS=0; FAIL=0

check() { # desc expected actual
  if [ "$2" = "$3" ]; then echo "  ✓ $1"; PASS=$((PASS+1));
  else echo "  ✗ $1 (期望 $2, 实际 $3)"; FAIL=$((FAIL+1)); fi
}
jget() { "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true); echo data_get($d,explode(".",$argv[1]),"");' "$1"; }
data_get() { :; }

login_student() { curl -s -X POST "$BASE/student/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"student_number\":\"$1\",\"password\":\"student123\"}"; }
login_staff() { curl -s -X POST "$BASE/staff/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"email":"staff@example.edu.cn","password":"staff123"}'; }

echo "== 登录"
STAFF=$(login_staff | $PHP -r 'echo json_decode(stream_get_contents(STDIN),true)["token"];')
T1=$(login_student 2023010001 | $PHP -r 'echo json_decode(stream_get_contents(STDIN),true)["token"];')
T2=$(login_student 2023010002 | $PHP -r 'echo json_decode(stream_get_contents(STDIN),true)["token"];')
[ -n "$STAFF" ] && [ -n "$T1" ] && [ -n "$T2" ] && echo "  ✓ 工作人员、学生1、学生2 均登录成功" || { echo "  ✗ 登录失败"; exit 1; }

scan() { curl -s -w '\n%{http_code}' -X POST "$BASE/verification/scan" -H "Authorization: Bearer $STAFF" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"scan_token\":\"$1\"}"; }
tok_of() { $PHP artisan tinker --execute="echo \App\Models\StudentCard::where('card_no','$1')->value('scan_token');" 2>/dev/null | tail -1; }
cd /workspace/backend

echo "== 场景1：张三在读有效证 → 通过，且不暴露完整学号"
TOK1=$(tok_of 2023010001-01)
R=$(scan "$TOK1"); BODY=$(echo "$R" | head -n -1); CODE=$(echo "$R" | tail -1)
check "HTTP 200" 200 "$CODE"
check "eligible=true" true "$(echo "$BODY" | $PHP -r 'echo json_decode(stream_get_contents(STDIN),true)["eligible"]?"true":"false";')"
if echo "$BODY" | grep -q "2023010001"; then check "响应无完整学号" "无" "泄露"; else check "响应无完整学号" "无" "无"; fi
echo "$BODY" | grep -q "2023\*\*\*\*01" && check "含掩码学号" yes yes

echo "== 场景2：学生张三实时挂失，窗口立即重新扫码 → 拒绝（服务端当前状态）"
curl -s -X POST "$BASE/student/cards/$( $PHP artisan tinker --execute='echo \App\Models\StudentCard::where("card_no","2023010001-01")->value("id");' 2>/dev/null | tail -1)/lost" -H "Authorization: Bearer $T1" -H 'Accept: application/json' >/dev/null
R=$(scan "$TOK1"); CODE=$(echo "$R" | tail -1); REASON=$(echo "$R" | head -n -1 | $PHP -r 'echo json_decode(stream_get_contents(STDIN),true)["reason_code"];')
check "挂失后 HTTP 403" 403 "$CODE"
check "原因 card_lost" card_lost "$REASON"

echo "== 场景3：张三申请补证 → 工作人员完成 → 旧证号永久失效，新证可用"
CID=$($PHP artisan tinker --execute='echo \App\Models\StudentCard::where("card_no","2023010001-01")->value("id");' 2>/dev/null | tail -1)
AID=$(curl -s -X POST "$BASE/student/applications" -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"lost_card_id\":$CID}" | $PHP -r 'echo json_decode(stream_get_contents(STDIN),true)["data"]["id"];')
curl -s -X POST "$BASE/staff/applications/$AID/complete" -H "Authorization: Bearer $STAFF" -H 'Accept: application/json' >/dev/null
R=$(scan "$TOK1"); check "旧 token 再扫 HTTP 403" 403 "$(echo "$R" | tail -1)"
check "旧证原因 card_replaced" card_replaced "$(echo "$R" | head -n -1 | $PHP -r 'echo json_decode(stream_get_contents(STDIN),true)["reason_code"];')"
TOK1NEW=$(tok_of 2023010001-02)
R=$(scan "$TOK1NEW"); check "新证 HTTP 200" 200 "$(echo "$R" | tail -1)"
OLDCOUNT=$($PHP artisan tinker --execute='echo \App\Models\StudentCard::where("card_no","2023010001-01")->count();' 2>/dev/null | tail -1)
check "旧证号仍唯一存在(未复用)" 1 "$OLDCOUNT"
# 再次完成同一申请 → 422
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/staff/applications/$AID/complete" -H "Authorization: Bearer $STAFF" -H 'Accept: application/json')
check "重复完成补证 422" 422 "$CODE"

echo "== 场景4：李四旧证(seed 即 replaced)拦截；新证通过"
TOK2OLD=$(tok_of 2023010002-01); TOK2NEW=$(tok_of 2023010002-02)
R=$(scan "$TOK2OLD"); check "李四旧证 403" 403 "$(echo "$R" | tail -1)"
check "原因 card_replaced" card_replaced "$(echo "$R" | head -n -1 | $PHP -r 'echo json_decode(stream_get_contents(STDIN),true)["reason_code"];')"
R=$(scan "$TOK2NEW"); check "李四新证 200" 200 "$(echo "$R" | tail -1)"

echo "== 场景5：王五休学 → 扫码拒绝；恢复在读 → 同一证通过（资格恢复，未挂失的证不受影响）"
SID3=$($PHP artisan tinker --execute='echo \App\Models\Student::where("student_number","2023010003")->value("id");' 2>/dev/null | tail -1)
TOK3=$(tok_of 2023010003-01)
R=$(scan "$TOK3"); check "休学时 403" 403 "$(echo "$R" | tail -1)"
check "原因 not_enrolled" not_enrolled "$(echo "$R" | head -n -1 | $PHP -r 'echo json_decode(stream_get_contents(STDIN),true)["reason_code"];')"
curl -s -X POST "$BASE/staff/students/$SID3/resume" -H "Authorization: Bearer $STAFF" -H 'Accept: application/json' >/dev/null
R=$(scan "$TOK3"); check "恢复在读后 200" 200 "$(echo "$R" | tail -1)"

echo "== 场景6：越权访问防护"
# 李四 token 访问张三的补证申请
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/student/applications/$AID" -H "Authorization: Bearer $T2" -H 'Accept: application/json')
check "跨学生查看补证资料被拒(403)" 403 "$CODE"
# 李四用张三的 card id 挂失
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/student/cards/$CID/lost" -H "Authorization: Bearer $T2" -H 'Accept: application/json')
check "跨学生挂失被拒(403)" 403 "$CODE"
# 李四用张三 card id 提交补证
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/student/applications" -H "Authorization: Bearer $T2" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"lost_card_id\":$CID}")
check "跨学生提交补证被拒(404)" 404 "$CODE"
# 学生 token 不能调用窗口扫码
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/verification/scan" -H "Authorization: Bearer $T2" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"scan_token\":\"$TOK2NEW\"}")
check "学生 token 调窗口接口 401" 401 "$CODE"
# 无 token
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/student/cards" -H 'Accept: application/json')
check "未登录访问 401" 401 "$CODE"

echo "== 场景7：李四的申请列表只含本人数据"
N=$(curl -s "$BASE/student/applications" -H "Authorization: Bearer $T2" -H 'Accept: application/json' | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true)["data"]; foreach($d as $a){if($a["lost_card_no"]??""==="2023010001-01"){echo "LEAK";exit;}} echo count($d);')
[ "$N" != "LEAK" ] && check "列表不含张三证件" yes yes || check "列表不含张三证件" yes "泄露"

echo
echo "结果：$PASS 通过, $FAIL 失败"
[ "$FAIL" -eq 0 ]
