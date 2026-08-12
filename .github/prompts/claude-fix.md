PHPUnit 整合測試{{ATTEMPT_LABEL}}失敗了。請根據以下測試輸出修復程式碼。

測試輸出：
```
{{TEST_OUTPUT}}
```

規則：
1. 仔細分析失敗的測試，找出根本原因{{EXTRA_HINT}}
2. 優先修改生產程式碼（inc/ 目錄下的 PHP）
3. 如果問題出在測試基礎類別（tests/Integration/TestCase.php）或測試 setUp/helper，也可以修改
4. 如果問題是環境或權限問題（如 uploads 目錄不可寫），可修改 .wp-env.json 或測試 bootstrap
5. {{REVERT_HINT}}修復後請 commit 變更（commit message 必須使用繁體中文）
