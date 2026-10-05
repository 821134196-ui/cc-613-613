import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { studentApi, apiError } from '../../api.js'
import { CARD_STATUS } from '../../labels.js'
import { useToast } from '../../toast.jsx'

export default function MyCards() {
  const [cards, setCards] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const toast = useToast()
  const navigate = useNavigate()

  async function load() {
    const { data } = await studentApi.get('/student/cards')
    setCards(data.data)
  }

  useEffect(() => {
    load().catch((err) => toast(apiError(err), 'bad'))
  }, [])

  async function reportLost(card) {
    if (!window.confirm(`确认挂失 ${card.card_no} 吗？挂失后该证立即不可使用，且无法撤销。`)) {
      return
    }
    setBusyId(card.id)
    try {
      const { data } = await studentApi.post(`/student/cards/${card.id}/lost`)
      toast(data.message || '挂失成功')
      await load()
    } catch (err) {
      toast(apiError(err), 'bad')
    } finally {
      setBusyId(null)
    }
  }

  if (!cards) return <p className="muted">加载中…</p>

  return (
    <div>
      <div className="card-box">
        <h3>我的学生证</h3>
        <p className="muted small">
          证件与在读资格分别管理：挂失只让这张证失效；补证完成后旧证号永久失效。每次窗口扫码都以服务端当前状态为准。
        </p>
      </div>

      {cards.length === 0 && (
        <div className="card-box">
          暂无证件。若已完成补证，请稍后刷新；如有疑问请联系学籍窗口。
        </div>
      )}

      {cards.map((card) => {
        const st = CARD_STATUS[card.status] || { label: card.status, cls: 'muted' }
        return (
          <div className="card-box" key={card.id}>
            <div className="row" style={{ justifyContent: 'space-between' }}>
              <div>
                <div style={{ fontSize: 17, fontWeight: 700 }}>
                  证号 {card.card_no}
                  <span className={`badge ${st.cls}`} style={{ marginLeft: 10 }}>{st.label}</span>
                </div>
                <div className="muted small" style={{ marginTop: 6 }}>
                  第 {card.sequence} 版 · 签发 {card.issued_at || '—'}
                  {card.lost_at && ` · 挂失时间 ${card.lost_at}`}
                </div>
              </div>
              {card.is_active ? (
                <button className="danger" disabled={busyId === card.id} onClick={() => reportLost(card)}>
                  {busyId === card.id ? '处理中…' : '挂失此证'}
                </button>
              ) : (
                <button className="primary" onClick={() => navigate('/student/applications')}>
                  {card.status === 'replaced' ? '查看补证记录' : '申请补证'}
                </button>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}
