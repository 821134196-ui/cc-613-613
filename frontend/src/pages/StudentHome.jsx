import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, getToken, clearSession } from '../api.js'

const STATUS_LABEL = { active: '有效', lost: '已挂失', replaced: '已被新证替代' }
const REQ_STATUS = { pending: '办理中', approved: '已办结', rejected: '已驳回' }
const REQ_TYPE = { report_loss: '挂失', reissue: '补证' }

export default function StudentHome() {
  const [me, setMe] = useState(null)
  const [cards, setCards] = useState([])
  const [requests, setRequests] = useState([])
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [reissueForm, setReissueForm] = useState({ reason: '', contact_phone: '' })
  const navigate = useNavigate()

  const refresh = useCallback(async () => {
    if (!getToken()) {
      navigate('/student/login')
      return
    }
    try {
      const [u, c, r] = await Promise.all([
        api('/student/me'),
        api('/student/cards'),
        api('/student/requests'),
      ])
      setMe(u.student)
      setCards(c.cards)
      setRequests(r.requests)
    } catch (e) {
      if (e.status === 401 || e.status === 403) {
        clearSession()
        navigate('/student/login')
      } else {
        setErr(e.message)
      }
    }
  }, [navigate])

  useEffect(() => {
    refresh()
  }, [refresh])

  const logout = async () => {
    try {
      await api('/student/logout', { method: 'POST' })
    } catch {
      /* ignore */
    }
    clearSession()
    navigate('/student/login')
  }

  const reportLoss = async (card) => {
    if (!window.confirm(`确认挂失证件 ${card.card_no}？挂失后旧证立即失效且无法恢复。`)) return
    setMsg('')
    setErr('')
    try {
      const d = await api(`/student/cards/${card.id}/loss`, {
        method: 'POST',
        body: { reason: '学生端自助挂失' },
      })
      setMsg(d.message)
      await refresh()
    } catch (e) {
      setErr(e.message)
    }
  }

  const submitReissue = async (e) => {
    e.preventDefault()
    setMsg('')
    setErr('')
    try {
      const d = await api('/student/reissues', { method: 'POST', body: reissueForm })
      setMsg(d.message)
      setReissueForm({ reason: '', contact_phone: '' })
      await refresh()
    } catch (ex) {
      setErr(ex.message)
    }
  }

  const hasPendingReissue = requests.some((r) => r.type === 'reissue' && r.status === 'pending')
  const hasLostCard = cards.some((c) => c.status === 'lost')

  return (
    <div className="stack">
      <div className="card row-between">
        <div>
          <h2 className="no-margin">{me?.name} 的学生中心</h2>
          <p className="muted no-margin">
            {me?.college}
            {me?.major ? ` · ${me.major}` : ''} · 学籍：
            <b className={me?.enrollment_status === 'active' ? 'ok' : 'warn'}>
              {me?.enrollment_status === 'active'
                ? '在读'
                : me?.enrollment_status === 'suspended'
                  ? '休学'
                  : '毕业'}
            </b>
          </p>
        </div>
        <button className="btn-ghost" onClick={logout}>
          退出登录
        </button>
      </div>

      {msg && <div className="alert ok">{msg}</div>}
      {err && <div className="alert err">{err}</div>}

      <section className="card">
        <h3>我的学生证</h3>
        {cards.length === 0 && <p className="muted">暂无证件。</p>}
        <div className="grid">
          {cards.map((c) => (
            <div key={c.id} className={`tile status-${c.status}`}>
              <div className="tile-head">
                <span className="pill" data-status={c.status}>
                  {STATUS_LABEL[c.status]}
                </span>
                <span className="muted small">第 {c.version} 版</span>
              </div>
              <div className="card-no">{c.card_no}</div>
              <div className="muted small">
                {c.lost_at ? `挂失于 ${c.lost_at}` : c.replaced_at ? `替代于 ${c.replaced_at}` : ' '}
              </div>
              <div className="tile-actions">
                {c.status === 'active' && (
                  <button className="btn danger" onClick={() => reportLoss(c)}>
                    挂失此证
                  </button>
                )}
                {c.status === 'lost' && <span className="warn">该旧证已永久失效，可申请补证</span>}
                {c.status === 'replaced' && <span className="muted">旧证号已永久失效</span>}
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="card">
        <h3>申请补证</h3>
        {!hasLostCard ? (
          <p className="muted">当前没有已挂失的证件。证件丢失请先在上方对有效证件进行挂失。</p>
        ) : hasPendingReissue ? (
          <p className="alert info">已有一笔补证申请办理中，受理后将在此显示新证。</p>
        ) : (
          <form onSubmit={submitReissue} className="form inline-form">
            <label>
              补证原因
              <input
                value={reissueForm.reason}
                onChange={(e) => setReissueForm({ ...reissueForm, reason: e.target.value })}
                placeholder="如：钱包遗失"
              />
            </label>
            <label>
              联系电话
              <input
                value={reissueForm.contact_phone}
                onChange={(e) =>
                  setReissueForm({ ...reissueForm, contact_phone: e.target.value })
                }
                placeholder="选填"
              />
            </label>
            <button className="btn primary">提交补证申请</button>
          </form>
        )}
      </section>

      <section className="card">
        <h3>办理进度</h3>
        {requests.length === 0 && <p className="muted">暂无申请记录。</p>}
        <table className="table">
          <thead>
            <tr>
              <th>类型</th>
              <th>状态</th>
              <th>关联证件</th>
              <th>新证</th>
              <th>时间</th>
            </tr>
          </thead>
          <tbody>
            {requests.map((r) => (
              <tr key={r.id}>
                <td>{REQ_TYPE[r.type]}</td>
                <td>
                  <span className="pill" data-status={r.status}>
                    {REQ_STATUS[r.status]}
                  </span>
                  {r.status === 'rejected' && r.reject_reason && (
                    <div className="muted small">原因：{r.reject_reason}</div>
                  )}
                </td>
                <td>{r.card ? `${r.card.card_no}（${STATUS_LABEL[r.card.status]}）` : '—'}</td>
                <td>{r.new_card ? r.new_card.card_no : '—'}</td>
                <td className="small">{r.created_at}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </div>
  )
}
