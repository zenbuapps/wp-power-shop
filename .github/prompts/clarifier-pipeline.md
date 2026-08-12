你現在處於 CI 全流程管線模式（@claude 開工）。
上方是 Issue #{{ISSUE_NUM}} 完整內容（包含所有留言與澄清紀錄），視為已完成的釐清。

### 你的任務：規格生成
- 根據 Issue 內容（含澄清留言）生成規格文件到 ./specs 目錄
- 使用 /aibdd.discovery、/aibdd.form.feature-spec 等 skill 產出完整 .feature 規格
- 如果 ./specs 目錄已有此功能的相關規格文件，請檢查是否需要更新
- 完成後 commit specs 到當前分支（commit message 必須使用繁體中文）
- 使用 `gh issue comment {{ISSUE_NUM}} --body "..."` 回報規格生成進度

### 嚴禁
- 不要實作功能程式碼（實作由後續 workflow step 的 tdd-coordinator 負責）
- 不要啟動任何 sub-agent（planner、tdd-coordinator 等）
- 完成 specs 的 commit 後就結束
