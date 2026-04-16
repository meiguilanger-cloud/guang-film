#!/bin/bash
# OpenClaw hourly status report for starwaves_project

PROJECT_ROOT="/root/.openclaw/workspace/starwaves_project"
PROJECT_RECORD="$PROJECT_ROOT/starwaves_project.md"

TOTAL_FILES=$(find "$PROJECT_ROOT" -type f | wc -l)
LATEST_COMMIT=$(git -C "$PROJECT_ROOT" log -1 --pretty=%B 2>/dev/null || echo 'N/A')
RECENT_LOG=$(tail -n 10 "$PROJECT_ROOT/logs/app.log" 2>/dev/null || echo 'No logs')
LATEST_FILES=$(find "$PROJECT_ROOT" -type f -mmin -120 | sed "s#$PROJECT_ROOT/##" | sort | tail -n 12)
[ -z "$LATEST_FILES" ] && LATEST_FILES='No recent file changes detected'

NOW=$(date +%s)
ONE_HOUR=$((NOW - 3600))
PLAY_COUNT=$(awk -F'|' -v start=$ONE_HOUR '{
    cmd="date -d \""$1"\" +%s"; cmd | getline ts; close(cmd);
    if (ts >= start) sum++
} END {print sum+0}' "$PROJECT_ROOT/logs/play.log" 2>/dev/null || echo 0)

CURRENT_PROJECT=$(cat <<'EOF'
1. 当前先收后台手机端：默认就是大号可用版，不靠用户手动放大
2. 立即补上用户 8 位账号，并让主账号显示为 `00000001`
3. 首页榜单改成 `星速飙升榜` 标准版：每页 10 首，共 5 页，榜单区内部滑动
4. 支付链继续补齐：后台确认充值申请后，正式给用户加积分
5. `星仔` 第一版继续推进：可见、登录后才能聊、先做站内客服与创作辅助
EOF
)

LATEST_PROGRESS=$(cat <<'EOF'
- 已补 `credits` 字段，并给 `beishake` 账号充入 `10000` 积分用于测试
- `profile.php` 已接入头像裁剪：支持拖动、缩放、圆形裁剪后保存
- 首页正在持续清理旧施工文案，并把服务卡改成解释服务含义而非直接谈钱
- 首页榜单方向已重新确定为单一 `星速飙升榜` 标准版，后续再进入完整榜单页
EOF
)

REPORT="📊 *Starwaves Music – 每小时进度报告*\n\n"
REPORT+="* 当前主线: *\n$CURRENT_PROJECT\n\n"
REPORT+="* 本小时真实进度: *\n$LATEST_PROGRESS\n\n"
REPORT+="* 最近改动文件: *\n$LATEST_FILES\n\n"
REPORT+="* 文件总数: $TOTAL_FILES\n"
REPORT+="* 最近一次提交: $LATEST_COMMIT\n"
REPORT+="* 最近一小时播放次数: $PLAY_COUNT\n\n"
REPORT+="* 项目记录文件: *\n$PROJECT_RECORD\n\n"
REPORT+="* 最近日志 (10 行):\n$RECENT_LOG\n"

echo -e "$REPORT"
