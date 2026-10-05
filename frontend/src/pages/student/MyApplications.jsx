import { useEffect, useState } from 'react'
import { studentApi, apiError } from '../../api.js'
import { APP_STATUS, CARD_STATUS } from '../../labels.js'
import { useToast } from '../../toast.jsx'

export default function MyApplications() {
  const [cards, setCards] = useState([])
  const [applications, setApplications] = useState(null)
  const [lostCardId, setLostCardId] = useState('')
  const [reason, setReason] = useState('证件遗失')
  const [submitting, setSubmitting] = useState(false)
  const toast = useToast()

  async function load() {
    const [cardsRes, appsRes] = await Promise.all([
      studentApi.get('/student/cards'),
      studentApi.get('/student/applications'),
    ])
    setCards(cardsRes.data.data)
    setApplications(appsRes.data.data)
    const firstLost = cardsRes.data.data.find((c) => c.status === 'lost')
    setLostCardId((prev) => prev || String(firstLost?.id || ''))
  }

  useEffect(() => {
    load().catch((err) => toast(apiError(err), 'bad'))
  }, [])

  async function submit(e) {
    e.preventDefault()
    if (!lostCardId) {
      toast('请选择需要补办的学生证', 'bad')
      return
    }
    setSubmitting(true)
    try {
      const { data } = await studentApi.post('/student/applications', {
        lost_card_id: Number(lostCardId),
        reason,
      })
      toast(data.message || '申请已提交')
      await load()
    } catch (err) {
      toast(apiError(err), 'bad')
    } finally {
      setSubmitting(false)
    }
  }

  const applicableCards = cards.filter((c) => c.status !== 'replaced')

  return (
    <div>
      <div className="card-box">
        <h3>提交补证申请</h3>
        <p className="muted small">
          选择遗失的证件提交申请。若证件尚未挂失，提交时系统会先为你挂失。补证完成后旧证号立即永久失效，新证获得新证号。
        </p>
        <form onSubmit={submit} className="grid2" style={{ alignItems: 'end' }}>
          <div>
            <label>遗失的学生证</label>
            <select value={lostCardId} onChange={(e) => setLostCardId(e.target.value)} style={{ width: '100%' }}>
              <option value="">请选择…</option>
              {applicableCards.map((c) => {
                const st = CARD_STATUS[c.status]
                return (
                  <option key={c.id} value={c.id}>
                    {c.card_no}（{st?.label || c.status}）
                  </option>
                )
              })}
            </select>
          </div>
          <div>
            <label>申请理由</label>
            <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={200} style={{ width: '100%' }} />
          </div>
          <div style={{ gridColumn: '1 / -1' }}>
            <button className="primary" disabled={submitting || applicableCards.length === 0}>
              {submitting ? '提交中…' : '提交补证申请'}
            </button>
            {applicableCards.length === 0 && (
              <span className="muted small" style={{ marginLeft: 12 }}>
                没有可补办的证件（旧证已补领新证，不能再次申请）。
              </span>
            )}
          </div>
        </form>
      </div>

      <div className="card-box">
        <h3>办理进度</h3>
        {!applications ? (
          <p className="muted">加载中…</p>
        ) : applications.length === 0 ? (
          <p className="muted">暂无补证申请。</p>
        ) : (
          <table>
            <thead>
              <tr>
                <th>旧证号</th>
                <th>旧证状态</th>
                <th>进度</th>
                <th>新证号</th>
                <th>提交时间</th>
                <th>完成时间</th>
              </tr>
            </thead>
            <tbody>
              {applications.map((a) => {
                const appSt = APP_STATUS[a.status] || { label: a.status, cls: 'muted' }
                const cardSt = CARD_STATUS[a.lost_card_status]
                return (
                  <tr key={a.id}>
                    <td>{a.lost_card_no}</td>
                    <td><span className={`badge ${cardSt?.cls || 'muted'}`}>{cardSt?.label || a.lost_card_status}</span></td>
                    <td><span className={`badge ${appSt.cls}`}>{appSt.label}</span></td>
                    <td>{a.new_card_no || <span className="muted">制证中</span>}</td>
                    <td>{a.submitted_at}</td>
                    <td>{a.completed_at || '—'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
