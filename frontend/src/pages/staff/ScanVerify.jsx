import { useEffect, useRef, useState } from 'react'
import QRCode from 'qrcode'
import { staffApi, apiError } from '../../api.js'
import { useToast } from '../../toast.jsx'

/**
 * 窗口扫码核验页。
 *
 * 关键设计：
 *  - 只把二维码里的 scan_token 发给服务端，结论（在读 + 证件有效）全部
 *    由服务端实时计算，页面不缓存、不推断，杜绝凭旧页面显示放行；
 *  - 结果区只展示核验所需身份信息（姓名、掩码学号、学院、掩码证号）；
 *  - “模拟扫描枪”仅用于本地演示：从服务端取当前证件清单，扫码即发起核验。
 */
export default function ScanVerify() {
  const [token, setToken] = useState('')
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(false)
  const [simCards, setSimCards] = useState(null)
  const [selectedSim, setSelectedSim] = useState('')
  const toast = useToast()
  const inputRef = useRef(null)

  useEffect(() => {
    staffApi
      .get('/simulation/cards')
      .then(({ data }) => setSimCards(data.data))
      .catch((err) => toast(apiError(err), 'bad'))
    inputRef.current?.focus()
  }, [])

  async function verify(scanToken) {
    const value = (scanToken ?? token).trim()
    if (!value) {
      toast('请先扫描或输入学生证二维码', 'bad')
      return
    }
    setLoading(true)
    setResult(null)
    try {
      const { data } = await staffApi.post('/verification/scan', { scan_token: value })
      // HTTP 200：eligible=true
      setResult(data)
    } catch (err) {
      // 403：服务端给出拒绝原因；其他错误（网络等）单独提示
      if (err.response?.status === 403 && err.response.data?.reason_code) {
        setResult(err.response.data)
      } else {
        toast(apiError(err), 'bad')
      }
    } finally {
      setLoading(false)
    }
  }

  function simulateScan(card) {
    setSelectedSim(String(card.id))
    setToken(card.scan_token)
    verify(card.scan_token)
  }

  return (
    <div>
      <div className="card-box">
        <h3>扫码核验优惠资格</h3>
        <div className="privacy-note">
          🔒 核验结论由服务端实时读取在读资格与证件状态生成；本页只显示姓名、掩码学号等必要信息，不显示完整学号。
          若学生刚刚挂失或补办，以本次扫码时服务端返回结果为准。
        </div>
        <div className="row">
          <input
            ref={inputRef}
            value={token}
            onChange={(e) => setToken(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && verify()}
            placeholder="扫描学生证二维码（或粘贴 scan token）"
            style={{ flex: 1 }}
          />
          <button className="primary" onClick={() => verify()} disabled={loading}>
            {loading ? '核验中…' : '核验'}
          </button>
        </div>
      </div>

      {result && <Verdict result={result} />}

      <div className="card-box">
        <h3>本地模拟扫描枪</h3>
        <p className="muted small">
          演示环境没有实体二维码设备。下面列出当前所有学生证（含历史旧证），点击“模拟扫码”即等同扫描该证二维码发起一次实时核验。
          可在学生端挂失、或在补证受理页完成补证后，回到此处重新扫描，观察旧证被拦截。
        </p>
        {!simCards ? (
          <p className="muted">加载证件列表…</p>
        ) : (
          <table>
            <thead>
              <tr>
                <th>学生</th>
                <th>掩码学号</th>
                <th>证号</th>
                <th>证件当前状态</th>
                <th>二维码</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {simCards.map((card) => (
                <tr key={card.id} style={selectedSim === String(card.id) ? { background: '#f2f7ff' } : undefined}>
                  <td>{card.student_name}</td>
                  <td>{card.student_number_masked}</td>
                  <td>{card.card_no_masked}</td>
                  <td>
                    <span className={`badge ${card.card_status === 'active' ? 'ok' : card.card_status === 'lost' ? 'bad' : 'muted'}`}>
                      {card.card_status === 'active' ? '有效' : card.card_status === 'lost' ? '已挂失' : '旧证已失效'}
                    </span>
                  </td>
                  <td><QrThumb token={card.scan_token} size={64} /></td>
                  <td>
                    <button className="ghost" onClick={() => simulateScan(card)}>模拟扫码</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

function Verdict({ result }) {
  const allow = result.eligible === true
  const id = result.identity

  return (
    <div className={`verdict ${allow ? 'allow' : 'deny'}`}>
      <div>{allow ? '✅ 核验通过' : '🚫 不予优惠'}</div>
      <div className="big">{allow ? '可以享受优惠' : '不能享受优惠'}</div>
      <div className="reason">{result.reason}</div>
      <div className="meta">
        原因码 <code>{result.reason_code}</code> · 服务端核验时间 {result.checked_at}
      </div>

      {id && (
        <div className="identity-grid">
          <div className="item">
            <div className="k">姓名</div>
            <div className="v">{id.name}</div>
          </div>
          <div className="item">
            <div className="k">学号（掩码）</div>
            <div className="v">{id.student_number_masked}</div>
          </div>
          <div className="item">
            <div className="k">学院</div>
            <div className="v">{id.college || '—'}</div>
          </div>
          <div className="item">
            <div className="k">证号（掩码）</div>
            <div className="v">{id.card_no_masked}</div>
          </div>
          <div className="item">
            <div className="k">证件状态</div>
            <div className="v">
              {id.card_status === 'active' ? '有效' : id.card_status === 'lost' ? '已挂失' : '旧证已失效'}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function QrThumb({ token, size = 64 }) {
  const [url, setUrl] = useState('')
  useEffect(() => {
    QRCode.toDataURL(token, { width: size, margin: 1 }).then(setUrl).catch(() => {})
  }, [token, size])
  if (!url) return null
  return <img src={url} width={size} height={size} alt="学生证二维码" />
}
