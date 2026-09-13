# XBoard + Xboard-Node Native Relay

## 目标

Native Relay 将原本由 GOST/极光等外部中转面板承担的 TCP/UDP 端口转发能力集成到 Xboard-Node Machine 模式中。

真实服务仍运行在落地节点 B。中转节点 A 不启动 VLESS/Trojan/VMess/Hysteria2 等协议服务，只做 L4 TCP/UDP Relay。

```text
Client
  |
  | encrypted protocol payload
  v
A:child.port
  |
  | Xboard-Node Native Relay
  v
B:parent.server_port
  |
  | real proxy service
  v
Internet
```

B 原始节点继续正常存在并可以直接下发给用户；A 只是 B 的另一个入口。

---

## 字段语义

| 字段 | Native Relay 中的意义 |
| --- | --- |
| `machine_id` | 任务在哪台机器的 Xboard-Node 上执行 |
| `parent_id` | 是否为 Relay，以及 Relay 指向哪个真实节点 |
| `child.host` | 客户端订阅中看到的 A 地址 |
| `child.port` | A 实际监听的 Relay 入口端口 |
| `parent.host` | B 的 Relay 目标地址 |
| `parent.server_port` | B 的真实协议服务端口 |

判定规则：

```text
parent_id = NULL, machine_id = NULL
=> 传统/外部管理节点

parent_id = NULL, machine_id != NULL
=> Xboard-Node 普通 Service

parent_id != NULL, machine_id = NULL
=> Legacy External Relay（例如现有 GOST），保持旧行为

parent_id != NULL, machine_id != NULL
=> Xboard-Node Managed Native Relay
```

因此 Managed Relay 的核心映射是：

```text
child.port -> parent.host:parent.server_port
```

`child.server_port` 可以继续保留复制节点时的原值，但不是 Relay 监听端口和目标端口的权威来源。

---

## 示例

真实 B：

```text
ID          = 100
name        = 香港直连
host        = 2.2.2.2
port        = 22222
server_port = 22222
parent_id   = NULL
machine_id  = B_MACHINE
```

复制 B 后创建 A：

```text
ID          = 101
name        = 香港-A中转
host        = 1.1.1.1
port        = 23456
server_port = 22222
parent_id   = 100
machine_id  = A_MACHINE
```

XBoard 给客户端下发：

```text
1.1.1.1:23456
```

A 上的 Xboard-Node 自动运行：

```text
0.0.0.0:23456 -> 2.2.2.2:22222
```

B 仍然运行真实协议服务：

```text
0.0.0.0:22222
```

最终：

```text
Client -----> B:22222 ----------------> Internet
   |
   +--------> A:23456 -> B:22222 -----> Internet
```

---

## 为什么 A 不需要运行代理协议

TCP Relay 实际存在两条 TCP 连接：

```text
Client <-> A
A <-> B
```

A 只把第一条连接中的应用层字节复制到第二条连接，再把 B 的回复复制回客户端。

A 不解析：

- UUID/password
- VLESS/Trojan/VMess
- TLS/Reality
- 用户套餐
- 设备限制
- 用户计费流量

真正的 TLS/Reality/协议握手仍然发生在客户端和 B 的协议服务之间。B 通常看到的网络层来源 IP 是 A，但协议中的用户身份不会改变。

UDP 使用按客户端地址维护的 UDP session 映射实现相同效果。

---

## 支持的 Relay transport

当前自动映射：

| 协议 | Relay |
| --- | --- |
| VLESS | TCP |
| VMess | TCP |
| Trojan | TCP |
| AnyTLS | TCP |
| Naive | TCP |
| HTTP | TCP |
| Hysteria/Hysteria2 | UDP |
| TUIC | UDP |
| Shadowsocks | TCP + UDP |
| SOCKS | TCP + UDP |
| Mieru | 根据 `transport` |

Managed Relay v1 要求 `child.port` 是一个 1-65535 的单一端口，不支持端口范围。

---

## 配置下发格式

普通 Service：

```json
{
  "mode": "service",
  "protocol": "vless",
  "listen_ip": "0.0.0.0",
  "server_port": 22222
}
```

Managed Relay：

```json
{
  "mode": "relay",
  "protocol": "vless",
  "relay": {
    "listen_ip": "0.0.0.0",
    "listen_port": 23456,
    "target_host": "2.2.2.2",
    "target_port": 22222,
    "networks": ["tcp"],
    "udp_idle_timeout": 90
  }
}
```

---

## 热更新行为

以下变更无需手工改 GOST：

### 修改 A 的连接端口

```text
A.port: 23456 -> 34567
```

Xboard-Node 自动关闭旧 listener 并监听新端口。

### 修改 B 的服务端口

```text
B.server_port: 22222 -> 33333
```

所有指向 B 的 Managed Relay 自动更新为：

```text
A:23456 -> B:33333
```

### 修改 B 的 host

所有子 Relay 自动更新目标地址。

### 修改 Service/Relay 模式或 machine

`parent_id` / `machine_id` 导致任务模式或所属机器改变时，XBoard 下发 `sync.nodes`，Xboard-Node 停止旧任务并以正确模式重新创建，避免把 Relay 配置错误热加载到协议 Service。

---

## 用户数据与计费

Managed Relay 不接收或处理用户列表/设备状态。

A 不进行：

```text
UUID/password auth
user traffic accounting
speed limit
device limit
```

用户认证和流量统计只发生在 B，因此不会因为 A + B 两层而双倍扣流量。

Relay 自身可统计连接数/字节/错误，但这些数据不进入用户计费。

---

## 父节点与状态

v1 只允许：

```text
Relay -> Root Service
```

即父节点自己的 `parent_id` 必须为空。

暂不支持：

```text
A -> C -> B
```

父节点被禁用或删除时，其 Managed Relay 会从对应 Machine 任务列表消失并停止监听。

当前 XBoard 的子节点可用状态仍主要继承父节点状态；v1 尚未提供独立的 Relay health UI。

---

## 复制节点

复制一个已经绑定 Machine 的节点时，新副本会自动清空 `machine_id`。

原因：如果直接复制 B 的 `machine_id`，复制动作完成后 B 机器可能立即尝试在相同 `server_port` 启动第二个协议服务并发生端口冲突。

推荐创建中转流程：

```text
1. 复制 B
2. 修改名称
3. host 改成 A 的公网 IP/域名
4. port 改成 A 的中转端口
5. server_port 保持原值
6. parent 选择 B
7. machine 选择 A
8. 保存
```

只有第 7 步明确选择 A 后，该副本才会成为 Managed Relay。

---

## 从现有 GOST 逐台迁移

旧节点：

```text
parent_id = B
machine_id = NULL
```

仍由 GOST 管理，不会被 Native Relay 自动接管。

迁移某台 A：

```text
1. 在 A 安装支持 Native Relay 的 Xboard-Node
2. 在 XBoard 创建/确认 Machine A
3. 确认 A Machine 在线
4. 将对应中转节点的 machine_id 改为 A
5. 确认 A 已监听 child.port
6. 使用客户端测试 A 节点
7. 确认 B 正常产生用户流量统计
8. 删除 A 上对应的旧 GOST 规则
```

建议一次迁移一台，保留原 B 直连节点作为回退入口。

---

## 部署顺序

必须先升级 Xboard-Node，再升级/使用支持 Relay 下发的 XBoard：

```text
1. Xboard-Node
2. XBoard
3. 逐个绑定 Managed Relay machine_id
4. 删除验证成功的旧 GOST 规则
```

---

## 验收检查

A 上：

```bash
ss -lntup | grep 23456
```

应看到 Xboard-Node 监听中转端口。

检查 Node 日志应看到类似：

```text
native relay started node_id=101 listen=0.0.0.0:23456 target=2.2.2.2:22222 networks=tcp
```

客户端使用 A 节点测试后：

- 可以正常访问互联网；
- B 的真实协议服务仍然在线；
- B 正常识别用户；
- 用户流量只在 B 计费一次；
- 停止 A 不影响 B 直连；
- 修改 B `server_port` 后 A 自动跟随；
- 修改 A `port` 后监听端口自动切换。

---

## 当前 v1 限制

- 不支持多级 Relay；
- Managed Relay 入口暂不支持端口范围；
- 没有独立 Relay health/metrics 管理 UI；
- Relay target 默认使用 `parent.host`，如果 `parent.host` 是 CDN/前置地址而不是 B 的实际可达地址，后续应增加独立 `relay_target_host`；
- 不启用 PROXY Protocol，因此 B 通常看到 A 的源 IP；
- Native Relay 是普通 L4 TCP/UDP forwarding，不实现 GOST 的 TLS/QUIC/WS 隧道、`-F` 协议链、多路复用等高级能力。

---

## 回滚

若某台 A 的 Native Relay 出现问题：

```text
1. 将该中转节点 machine_id 清空
2. Xboard-Node 自动停止 Managed Relay
3. 恢复原 GOST 规则 A:child.port -> B:server_port
```

`parent_id` 可以保留，因此订阅节点结构无需改变。
