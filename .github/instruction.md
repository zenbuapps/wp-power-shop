# `.github/` 目錄架構指引

> **用途**：本文件描述 `.github/` 目錄的組織慣例、設計哲學與可複製的模板。供 AI 在為**其他專案**建立 GitHub Actions workflow 體系時作為參考。
>
> **範本來源**：Power Shop（WordPress Plugin，前後端分離，Claude Code Action 驅動的 AI Agent Pipeline）。
>
> **核心哲學**：CI-driven AI Agent Pipeline — 透過 workflow 層級串接多個 agent（clarifier → planner → tdd-coordinator → browser-tester），每個 agent 獨立 step 執行，agent 之間以 **Git commit**、**GitHub Issue comment**、**step outputs** 為橋樑，不依賴 sub-agent 交接。

---

## 一、目錄結構總覽

```
.github/
├── workflows/              # GitHub Actions workflow 檔（YAML）
│   ├── <main-pipe>.yml     # 核心 pipeline（多 job，串接 agent）
│   ├── <main-pipe>.md      # pipeline 的中文規格書（必配）
│   ├── <side>.yml          # 側翼 workflow（如 issue 展開、release）
│   └── <local-test>.yml    # act 本機測試用（不發布用）
├── actions/                # Composite Action（可重用邏輯封裝）
│   └── <name>/
│       └── action.yml
├── prompts/                # 給 Claude agent 的 prompt 模板
│   └── <agent>-<mode>.md
├── templates/              # GitHub comment 模板（佔位符 + 靜態文本）
│   └── <purpose>-comment.md
├── scripts/                # Shell / Node 工具腳本
│   └── <utility>.sh
└── instruction.md          # 本文件
```

**分層原則**：
- **workflows/**：定義「何時觸發、做什麼順序、誰依賴誰」。只寫協調邏輯，不寫大段 prompt 或複雜判定。
- **actions/**：抽取被多個 workflow 或多個 step 重複使用的單元（如 AI 呼叫重試、環境建置）。
- **prompts/**：所有超過 20 行、需要精修、可能被多次引用的 AI 指令。
- **templates/**：所有會被 `gh issue comment` / `gh pr create` 用到的長文本，保留佔位符由 shell 端替換。
- **scripts/**：跨 workflow 可重用的 shell 腳本（CDN 上傳、資料解析等）。

---

## 二、各子目錄職責與設計規範

### 2.1 `workflows/` — 協調層

**必備檔案**：

1. **`<main-pipe>.yml`**：專案主 pipeline，通常拆成 2 個以上 job：
   - **Job 1**（如 `claude`）：AI agent 串接（需求釐清 → 規劃 → 實作）
   - **Job 2**（如 `integration-tests`）：自動化驗證（測試 → 修復 → AI 驗收 → 自動 PR）
   - Job 2 透過 `needs: <job-1>` 依賴 Job 1 的 outputs

2. **`<main-pipe>.md`**：同名 Markdown，作為該 workflow 的**中文規格書**。內容應涵蓋：
   - 觸發事件 + concurrency 規則
   - 關鍵字 → 模式對照表（若有）
   - 每個 Job 的 outputs 清單
   - Steps 流程表（分段 A/B/C... + 核心動作）
   - 外部依賴資產清單（composite action、prompts、templates、scripts、marketplace、secrets）
   - Gotchas（已知陷阱、待修 bug）
   - 修改自查清單

3. **`act-test.yml`**（選配）：本機 `act` 工具測試用，驗證多 job 依賴與 artifact 傳遞，**不應用於正式 push**。

**觸發模式慣例**：

```yaml
on:
  issue_comment:
    types: [created]
  pull_request_review_comment:
    types: [created]
  pull_request_review:
    types: [submitted]

concurrency:
  group: ${{ github.workflow }}-${{ github.event.issue.number || github.event.pull_request.number }}
  cancel-in-progress: true
```

**關鍵字 → 模式解析**（若 workflow 支援多模式）：
- 解析優先序由強到弱：`全自動` > `PR` > `開工` > `互動`
- 以 `grep -qiE` 匹配關鍵字，設定 `PIPELINE_MODE` / `FULL_AUTO_MODE` / `PR_MODE` 等 env flag
- 注意**英文關鍵字要加字邊界**（`\bok\b` 而非 `ok`），否則容易誤觸

**Job Outputs 命名慣例**：
```yaml
outputs:
  branch_name:     # 作業分支
  issue_num:       # Issue 編號
  initial_sha:     # 進入時的 HEAD（用於偵測變更）
  <agent>_ok:      # 各 agent 的成敗旗標（skipped 視為 OK）
  has_changes:     # 是否有 commit 或 working tree 變動
  <mode>_mode:     # 各種模式旗標
  run_next_job:    # 是否觸發下一個 job
```

### 2.2 `actions/` — 可重用邏輯

**典型範例**：`actions/claude-retry/action.yml`

包裝 `anthropics/claude-code-action@v1`，實作 **3 次重試 + 指數退避（30s / 60s）**，避免 API 暫時性失敗讓整個 pipeline 爆掉。

關鍵設計：
- **`continue-on-error: true`**：讓每次嘗試都不會中斷 workflow
- **`if: steps.<prev>.outcome == 'failure'`**：後續嘗試只在前次失敗時執行
- **最終 evaluate step**：彙整三次的 `outcome`，輸出統一的 `success` flag 給上層 workflow 判斷

**呼叫方式**（在 `workflows/<main-pipe>.yml`）：
```yaml
- name: "Run Clarifier Agent"
  id: clarifier
  uses: ./.github/actions/claude-retry
  with:
    prompt: ${{ env.COMBINED_PROMPT }}
    agent: zenbu-powers:clarifier
    max_turns: '200'
    claude_code_oauth_token: ${{ secrets.CLAUDE_CODE_OAUTH_TOKEN }}
```

**何時應抽成 composite action**：
- 邏輯會被 2 個以上 step 重複使用
- 包含多個原子操作（多次 API 呼叫、環境設置、彙整判斷）
- 需要統一錯誤處理策略

**何時不必抽**：
- 僅被單一 step 使用
- 只是一兩行 shell 指令

### 2.3 `prompts/` — AI 指令模板

**命名慣例**：`<agent>-<mode>.md`
- `clarifier-interactive.md` — 互動式澄清（第一輪提至少 5 題）
- `clarifier-pipeline.md` — Pipeline 模式（跳過互動直接產 specs）
- `planner.md` — 讀 specs、產實作計畫
- `tdd-coordinator.md` — TDD Red/Green/Refactor 循環協調
- `<agent>-fix.md` — 修復模式 prompt（選配）

**抽取原則**（哪些內容應從 yml 搬到 `.md` 檔）：

| 情境 | 放哪裡 |
|------|--------|
| Prompt > 20 行 | `.md` 檔 |
| 包含角色定義（「你是 clarifier...」） | `.md` 檔 |
| 會被多處引用 | `.md` 檔 |
| 僅 5 行以內的字段說明 | yml 內嵌 |
| 包含動態資訊（issue number、branch name） | yml 以 placeholder 注入 |
| 夾雜複雜 shell 判定邏輯 | yml 的 shell step |

**佔位符慣例**：使用 `{{PLACEHOLDER_NAME}}`（雙大括號 + 全大寫 + 底線）

**在 workflow 中注入 prompt**：
```bash
PROMPT_TEMPLATE=$(cat .github/prompts/clarifier-interactive.md)
PROMPT_TEMPLATE="${PROMPT_TEMPLATE//\{\{ISSUE_NUM\}\}/$ISSUE_NUM}"
PROMPT_TEMPLATE="${PROMPT_TEMPLATE//\{\{BRANCH_NAME\}\}/$BRANCH_NAME}"
echo "COMBINED_PROMPT<<EOF" >> $GITHUB_ENV
echo "$PROMPT_TEMPLATE" >> $GITHUB_ENV
echo "EOF" >> $GITHUB_ENV
```

### 2.4 `templates/` — Comment 模板

**命名慣例**：`<purpose>-comment.md`
- `test-result-comment.md` — 測試結果回報
- `acceptance-comment.md` — AI 驗收報告
- `pipeline-upgrade-comment.md` — Pipeline 模式升級通知

**設計原則**：
- **Template 不含條件邏輯**：所有判定在 shell 端完成，只注入最終結果字串
- **佔位符統一格式**：`{{NAME}}`
- **常用佔位符**：`{{STATUS_EMOJI}}` / `{{STATUS_TEXT}}` / `{{MAIN_CONTENT}}` / `{{RUN_ID}}` / `{{CYCLE}}` / `{{SUMMARY_TABLE}}`

**注入流程**：
```bash
# 1. 讀模板
COMMENT=$(cat .github/templates/test-result-comment.md)

# 2. 逐一替換佔位符
COMMENT="${COMMENT//\{\{STATUS_EMOJI\}\}/$STATUS_EMOJI}"
COMMENT="${COMMENT//\{\{CYCLE\}\}/$CYCLE}"

# 3. 發到 Issue / PR
gh issue comment "$ISSUE_NUM" --body "$COMMENT"
```

### 2.5 `scripts/` — 工具腳本

**適用場景**：
- CDN 上傳、artifact 打包等需要外部 CLI 的工作
- 跨 workflow 共用的資料解析邏輯
- 超過 30 行的 shell 邏輯（避免污染 yml）

**命名慣例**：`<action>-<target>.sh`（動詞 + 受詞），例：`upload-to-bunny.sh`。

**呼叫方式**：
```yaml
- name: Upload media
  env:
    BUNNY_STORAGE_HOST: ${{ secrets.BUNNY_STORAGE_HOST }}
    BUNNY_STORAGE_ZONE: ${{ secrets.BUNNY_STORAGE_ZONE }}
  run: bash .github/scripts/upload-to-bunny.sh "$LOCAL_DIR" "$REMOTE_PATH"
```

---

## 三、核心設計模式

### 3.1 Two-Job Pipeline 模式

將長流程拆成兩個 job，好處：
- **Job 1 專注 AI 創作**（需求釐清、規劃、實作）
- **Job 2 專注自動化驗證**（測試、修復、驗收、PR）
- Job 2 透過 `if` 條件按需啟動，避免純澄清場景浪費資源
- 各自獨立 timeout（AI 任務 180 min，測試 150 min）

**Job 依賴範例**：
```yaml
integration-tests:
  needs: claude
  if: |
    needs.claude.outputs.run_integration_tests == 'true' &&
    (
      needs.claude.outputs.pr_mode == 'true' ||
      (needs.claude.outputs.claude_ok == 'true' && needs.claude.outputs.has_changes == 'true')
    )
  outputs:
    final_result_status: ${{ steps.final_result.outputs.status }}
```

### 3.2 Agent 串接模式（Workflow-Level Orchestration）

**原則**：agent 之間**不靠 sub-agent 交接**，而是透過：
- **Git commit**：前一個 agent 的產出（如 specs/ 目錄檔案）作為下一個 agent 的輸入
- **Step outputs**：傳遞 flag（`specs_available`、`planner_ok`、`has_changes`）
- **GitHub Issue comment**：作為人類可讀的進度日誌

**Step 分段**：
```
A. 前置（checkout、分支解析、SHA 保存）
B. 模式解析（parse_agent、fetch_context）
C. Agent 1（如 clarifier）
D. 橋接（偵測產出、動態升級模式）
E. Agent 2（如 planner）
F. Agent 3（如 tdd-coordinator）
G. 收尾（匯整 outputs、git push）
```

### 3.3 三循環測試修復模式

```
test_cycle_1 (失敗)
  → claude_fix_1 (讀 failure log、修 bug)
  → test_cycle_2 (失敗)
  → claude_fix_2
  → test_cycle_3 (最終，無修復，決定 pipeline 成敗)
```

所有步驟設 `continue-on-error: true`，由最終 evaluate step 判定整體結果。

### 3.4 `claude-code-action` 標準用法

**完整呼叫**：
```yaml
- uses: anthropics/claude-code-action@v1
  with:
    claude_code_oauth_token: ${{ secrets.CLAUDE_CODE_OAUTH_TOKEN }}
    additional_permissions: |
      actions: read
    settings: .claude/settings.json
    plugin_marketplaces: https://github.com/<org>/<marketplace-repo>.git
    plugins: <marketplace>@<marketplace>
    prompt: ${{ env.COMBINED_PROMPT }}
    claude_args: |
      --dangerously-skip-permissions
      --max-turns 200
      --model claude-opus-4-6
      --agent <marketplace>:<agent-name>
```

**關鍵參數說明**：

| 參數 | 用途 |
|------|------|
| `claude_code_oauth_token` | 必填，Claude API 授權 |
| `settings` | 指向專案 `.claude/settings.json`，決定 agent/skill/hook 行為 |
| `plugin_marketplaces` | 自訂 agent / skill 來源 repo（git URL） |
| `plugins` | 啟用的 plugin，格式 `<name>@<marketplace>` |
| `prompt` | 主指令（可從 `.md` 檔讀出並替換佔位符） |
| `--dangerously-skip-permissions` | CI 環境必加，跳過互動式確認 |
| `--max-turns` | 對話輪數上限（澄清 120、實作 200） |
| `--agent` | 指定要呼叫的 agent（格式 `<marketplace>:<agent>`） |

**何時用 `claude-code-action@v1` vs `claude-retry` composite action**：
- **需要高可靠性**（clarifier / planner / tdd）→ 用 `claude-retry`
- **容忍一次失敗**（測試修復、smoke 驗收）→ 直接用 `claude-code-action@v1`

---

## 四、標準操作配方（Cookbook）

### 4.1 分支解析與保護

**目標**：為每個 Issue 建立或復用 `issue/{N}-{timestamp}` 作業分支，避免多次執行互相覆蓋。

```yaml
- name: Resolve branch
  id: resolve_branch
  run: |
    EXISTING=$(git ls-remote --heads origin | grep -o "issue/${ISSUE_NUM}-[0-9]*" | head -1 || echo "")
    if [ -n "$EXISTING" ]; then
      BRANCH_NAME="$EXISTING"
    else
      BRANCH_NAME="issue/${ISSUE_NUM}-$(date +%Y%m%d-%H%M%S)"
    fi
    echo "branch_name=$BRANCH_NAME" >> $GITHUB_OUTPUT
```

**推送策略**：一律使用 `git push --force-with-lease origin "$BRANCH_NAME"`，防止競態條件下覆蓋他人推送。

### 4.2 變更偵測

```yaml
- name: Detect changes
  id: detect
  run: |
    if git diff --quiet "$INITIAL_SHA" HEAD -- specs/; then
      echo "specs_changed=false" >> $GITHUB_OUTPUT
    else
      echo "specs_changed=true" >> $GITHUB_OUTPUT
    fi
```

### 4.3 Comment 回寫（支援長文本 + Emoji）

```bash
STATUS_EMOJI="✅"
STATUS_TEXT="通過"
COMMENT=$(cat .github/templates/test-result-comment.md)
COMMENT="${COMMENT//\{\{STATUS_EMOJI\}\}/$STATUS_EMOJI}"
COMMENT="${COMMENT//\{\{STATUS_TEXT\}\}/$STATUS_TEXT}"
gh issue comment "$ISSUE_NUM" --body "$COMMENT"
```

**注意**：若 comment 包含特殊字元（引號、反斜線），建議改用 stdin：
```bash
printf '%s\n' "$COMMENT" | gh issue comment "$ISSUE_NUM" --body-file -
```

### 4.4 Artifact 保存（失敗分析用）

```yaml
- name: Upload test logs
  if: always()
  uses: actions/upload-artifact@v4
  with:
    name: test-logs-${{ github.run_id }}
    path: |
      tests/results/**
      /tmp/smoke-media/**
    retention-days: 7
```

### 4.5 自動 PR 建立

```yaml
- name: Create PR
  if: steps.run_ai_acceptance.outcome != 'failure'
  env:
    GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
  run: |
    gh pr create \
      --base master \
      --head "$BRANCH_NAME" \
      --title "feat(#${ISSUE_NUM}): <自動生成標題>" \
      --body "$(cat <<EOF
    ## 摘要
    自動化 pipeline 完成 Issue #${ISSUE_NUM}

    ## 測試結果
    ![tests](https://img.shields.io/badge/tests-passed-green)

    ## AI 驗收
    ![acceptance](https://img.shields.io/badge/acceptance-passed-green)

    Closes #${ISSUE_NUM}
    EOF
    )"
```

---

## 五、Secrets 與環境變數清單（範本）

建立新專案時需在 repo settings 備齊：

| Secret | 用途 |
|--------|------|
| `CLAUDE_CODE_OAUTH_TOKEN` | Claude Code Action 必備 |
| `GITHUB_TOKEN` | Actions 預設提供，寫入 comment/PR 需擴權 |
| `<CDN>_STORAGE_HOST` / `_ZONE` / `_PASSWORD` | 媒體 CDN 上傳（選配） |
| `<CDN>_PUBLIC_URL` | 回寫 comment 時用的公開 URL |

**Permissions 最小集**：
```yaml
permissions:
  contents: write        # push 分支
  pull-requests: write   # 建 PR
  issues: write          # comment 回寫
  id-token: write        # OIDC（若用雲端認證）
  actions: read          # 讀其他 run 狀態
```

---

## 六、為新專案建立 `.github/` 的步驟清單

1. **確立 pipeline 模式**：
   - 單一 job vs 多 job？
   - 支援哪些觸發模式（互動澄清 / 全自動 / 僅 PR）？
   - 需要哪些 agent（clarifier / planner / tdd / reviewer / tester）？

2. **建立目錄骨架**：
   ```
   .github/{workflows,actions,prompts,templates,scripts}/
   ```

3. **抽取 prompt**：
   - 從現有 agent 指令中，將超過 20 行或可重用的內容搬到 `prompts/<agent>-<mode>.md`
   - 確定佔位符集合（`{{ISSUE_NUM}}`、`{{BRANCH_NAME}}`、`{{CONTEXT}}` 等）

4. **建立 composite action**：
   - 先做 `claude-retry/action.yml`（3 次重試 + 指數退避）
   - 若有環境建置、資料庫初始化等重複邏輯，也抽成 composite

5. **設計 comment templates**：
   - 測試結果、驗收結果、模式升級通知
   - 統一佔位符命名

6. **編寫主 pipeline yml**：
   - 分段 A~G（Job 1）/ H~M（Job 2）
   - 每段一個 `id`，方便後續 step 引用
   - 關鍵 step 設 `continue-on-error: true`，由最終 evaluate step 判定整體結果

7. **撰寫同名 `.md` 規格書**（必備）：
   - 放在 `workflows/<main-pipe>.md`
   - 內容包含觸發模式、Job outputs、Steps 流程表、Gotchas、修改自查清單

8. **建立本機測試 workflow**：
   - `workflows/act-test.yml`
   - 用 `act` 工具驗證 job 依賴與 artifact 傳遞

9. **備齊 Secrets**（見第五節）

10. **首次 dry-run**：
    - 在測試 Issue 留言觸發
    - 確認每個 step 的 outputs 正確傳遞
    - 驗證 comment 回寫格式

---

## 七、常見陷阱（Gotchas）

### 7.1 Step ID 引用錯誤
多步驟之間引用 outputs 時，步驟 `id` 拼錯不會在 yml 驗證階段報錯，只會在執行時得到空值。**修改 step 後務必同步檢查所有 `steps.<id>.outputs.` 引用**。

### 7.2 英文關鍵字匹配過寬
`grep -qiE 'ok|go|start'` 會匹配到日常對話中的 `ok`、`got it`。應使用字邊界：`grep -qiE '\b(ok|go|start)\b'`。

### 7.3 `--force-with-lease` vs `--force`
多個 workflow run 平行執行時，`--force` 會互相覆蓋。使用 `--force-with-lease` 在遠端有他人推送時會拒絕，避免掉其他 run 的成果。

### 7.4 Prompt 寫死在 workflow
當某段 prompt 超過 30 行或會被複製到多處，應立刻抽到 `prompts/` 目錄。寫死在 yml 的 heredoc 難以 review 與維護。

### 7.5 Placeholder 替換的特殊字元
若 prompt / template 中包含 `&`、`/` 等字元，用 `sed` 替換會出錯。改用 bash 原生 `${VAR//old/new}` 較安全。

### 7.6 Concurrency 群組命名
`concurrency.group` 應包含 issue/PR 編號，讓「同一 issue 的新留言取消舊 run」而不是「全專案只能跑一個 workflow」。

### 7.7 Job Outputs 型別
`needs.<job>.outputs.<key>` 永遠是字串，比較時要用 `== 'true'` 而非 `== true`。

---

## 八、版本與相容性

- **`anthropics/claude-code-action`**：建議 pin `@v1`（或 `@v1.x.x`），避免 breaking change
- **`actions/checkout`**、**`actions/upload-artifact`**：使用 `@v4`
- **Runner**：`ubuntu-latest`（若專案相依特定 PHP/Node 版本，在 setup step 明確指定）

---

## 九、延伸閱讀

- 具體實作範例：本專案 `workflows/pipe.yml` + `workflows/pipe.md`
- Composite action 範例：本專案 `actions/claude-retry/action.yml`
- Prompt 模板範例：本專案 `prompts/clarifier-interactive.md`
- Comment template 範例：本專案 `templates/test-result-comment.md`
