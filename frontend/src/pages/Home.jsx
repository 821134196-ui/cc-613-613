import { Link } from 'react-router-dom'

export default function Home() {
  return (
    <div className="home-hero">
      <h1>高校学生证优惠资格核验与挂失系统</h1>
      <p className="muted">资格与证件分离管理 · 旧证挂失即失效 · 核验结论以服务端实时状态为准</p>

      <div className="home-cards">
        <Link to="/student/login">
          <h2>🎓 学生端</h2>
          <p>挂失学生证、申请补证、查询办理进度（仅可查看本人的证件与申请）。</p>
        </Link>
        <Link to="/staff/login">
          <h2>🏫 窗口核验端</h2>
          <p>扫码核验优惠资格、受理补证申请、办理休学与恢复（仅展示掩码身份信息）。</p>
        </Link>
      </div>

      <div className="demo-hint">
        <strong>演示账号</strong>
        <ul>
          <li>学生：学号 <code>2023010001</code>（张三，在读有效证）/ <code>2023010002</code>（李四，已补证，旧证失效）/ <code>2023010003</code>（王五，休学中）/ <code>2023010004</code>（赵六，已挂失待补证），密码均为 <code>student123</code></li>
          <li>窗口：<code>staff@example.edu.cn</code> / <code>staff123</code></li>
          <li>窗口端“扫码”为本地模拟：可手动输入 token，或从模拟扫描枪列表选择证件。</li>
        </ul>
      </div>
    </div>
  )
}
