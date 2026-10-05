import { useCallback, useEffect, useState } from 'react'
import { api } from '../api.js'

/**
 * 窗口扫码核验端（无需登录）。
 * 关键点：
 *  - 扫到的只是二维码令牌，不是学号；
 *  - 每次核验都向服务端发起新的实时查询，页面不缓存上一次结论，
 *    「重新核验」可直观证明放行依据永远是服务端当前状态；
 *  - 结果只展示核验所需的最小身份信息（脱敏学号）。
 */
export default function CounterVerify() {
  const [targets, setTargets] = useState([])
  const [manualToken, setManualToken] = useState('')
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [scannedToken, setScannedToken] = useState('')
  const resultRef = useRef(null)
  resultRef.current = result

  const loadTargets = useCallback(async () => {
    try {
      const d = await api('/simulator/targets', { auth: false })
      setTargets(d.targets)
    } catch (e) {
      setError(`模拟扫码服务不可用：${e.message}`)
    }
  }, [])

  useEffect(() => {
    loadTargets()
  }, [loadTargets])

  const verify = useCallback(async (token) => {
    if (!token) return
    setLoading(true)
    setError('')
    // 关键：不读取/复用上一次页面结果，强制以服务端当前状态为准。
    setResult(null)
    setScannedToken(token)
    try {
      const d = await api('/verify', { method: 'POST', auth: false, body: { qr_token: token } })
      setResult(d)
    } catch (e) {
      setError(e.message)
    } finally {
      setLoading(false)
    }
  }, [])

  return (
    <div className="stack">
      <div className="card">
        <h2 className="no-margin">🪟 窗口优惠核验</h2>
        <p className="muted">
          扫描学生证二维码（本地为模拟扫码服务）。系统实时查询服务端当前的
          <b> 学籍资格</b> 与 <b>证件有效性</b>，旧证挂失或被替代后立即无法通过。
        </p>

        <div className="scan-grid">
          <div className="scan-box">
            <h3>① 模拟扫码</h3>
            {error && <div className="alert err">{error}</div>}
            <div className="target-list">
              {targets.map((t) => (
                <button
                  key={t.qr_token}
                  className="target-btn"
                  onClick={() => verify(t.qr_token)}
                  disabled={loading}
                  title={`令牌：${t.qr_token}`}
                >
                  <span className="qr-icon">▦</span>
                  <span className="target-label">{t.label}</span>
                </button>
              ))}
            </div>
          </div>

          <div className="scan-box">
            <h3>② 手动输入/粘贴二维码令牌</h3>
            <div className="manual-row">
              <input
                value={manualToken}
                onChange={(e) => setManualToken(e.target.value.trim())}
                placeholder="粘贴 36 位 UUID 令牌"
              />
              <button className="btn primary" disabled={loading || !manualToken} onClick={() => verify(manualToken)}>
                核验
              </button>
            </div>
            {scannedToken && (
              <p className="muted small token-line">本次令牌：{scannedToken}</p>
            )}
          </div>
        </div>
      </div>

      {loading && <div className="card verdict loading">正在向服务端实时核验…</div>}

      {result && !loading && (
        <Verdict result={result} onReverify={() => verify(scannedToken)} reloading={loading} />
      )}

      {!result && !loading && (
        <div className="card placeholder-verdict">
          扫码后此处显示是否可以享受学生优惠及原因
        </div>
      )}
    </div>
  )
}

function Verdict({ result, onReverify, reloading }) {
  const { allowed, identity, reasons, checks, checked_at } = result

  return (
    <div className={`card verdict ${allowed ? 'pass' : 'deny'}`}>
      <div className="verdict-head">
        <div className="verdict-big">
          {allowed ? '✅ 可以享受学生优惠' : '⛔ 不能享受学生优惠'}
        </div>
        <div className="verdict-actions">
          <button className="btn-ghost" onClick={onReverify} disabled={reloading}>
            🔄 重新核验（以服务端当前状态为准）
          </button>
        </div>
      </div>

      {identity && (
        <div className="identity">
          <div>
            <span className="muted">姓名</span>
            <b>{identity.name}</b>
          </div>
          <div>
            <span className="muted">院系</span>
            <span>
              {identity.college}
              {identity.major ? ` · ${identity.major}` : ''}
            </span>
          </div>
          <div>
            <span className="muted">学号（脱敏）</span>
            <code>{identity.student_no_masked}</code>
          </div>
          <div>
            <span className="muted">证件号（脱敏）</span>
            <code>{identity.card_no_masked}</code>
          </div>
          <div>
            <span className="muted">证件版本</span>
            <span>第 {identity.card_version} 版</span>
          </div>
        </div>
      )}

      <div className="checks">
        <Check ok={checks.card_exists} label="证件存在" />
        <Check ok={checks.card_valid} label="证件有效（未挂失/未替代）" />
        <Check ok={checks.enrolled} label="学籍在读" />
      </div>

      <ul className="reasons">
        {reasons.map((r, i) => (
          <li key={i}>{r}</li>
        ))}
      </ul>

      <p className="muted small right">服务端核验时间：{checked_at}</p>
      <p className="muted small warn-note">
        注意：本结论由服务端实时查询生成。若学生此刻正在另一终端挂失旧证，点击「重新核验」后结论会立即变化，窗口不得仅凭此前页面显示放行。
      </p>
    </div>
  )
}

function Check({ ok, label }) {
  return (
    <div className={`check ${ok ? 'ok' : 'no'}`}>
      <span className="check-icon">{ok ? '✓' : '✕'}</span>
      {label}
    </div>
  )
}
