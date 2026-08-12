<!--
  用途：AI 驗收 Smoke Test 報告 Issue Comment
  使用方式：JS 端用 fs.readFileSync 讀取後，renderTemplate() 替換佔位符

  佔位符清單：
    {{STATUS_EMOJI}}   - ✅ 或 ⚠️
    {{STATUS_TEXT}}     - 通過 或 異常
    {{MAIN_CONTENT}}   - 主要報告內容（processedReport 或 fallback 媒體區塊）
    {{RUN_ID}}         - GitHub Actions run ID
-->
## 🖥️ AI 驗收 {{STATUS_EMOJI}} {{STATUS_TEXT}} — zenbu-powers:browser-tester
{{MAIN_CONTENT}}
---
*🤖 Smoke Test 由 CI 自動執行（run `{{RUN_ID}}`）*
