/**
 * Partner 修改密碼 mutation hook
 *
 * 封裝 POST /partner-auth/change-password。
 *
 * 設計重點：
 * - retry: 0：密碼操作不重試（避免重複觸發 rate-limit）
 * - mutation 在 loading 期間，呼叫端應依 isLoading disable submit button 防雙擊
 * - 成功 / 失敗的副作用（force logout、notification）由呼叫端 ChangePassword 頁負責，
 *   本 hook 保持薄、不耦合 router / notification
 * - mutationKey：固定為 ['partner-change-password']，方便日後跨頁查 mutation 狀態
 */

import { useMutation } from '@tanstack/react-query'
import type { AxiosError } from 'axios'

import {
	changePassword,
	type TChangePasswordInput,
	type TChangePasswordOutput,
} from '../auth/api'

/**
 * 取得修改密碼用的 mutation hook
 *
 * @return {Object} TanStack Query v4 mutation result，含 mutate / mutateAsync /
 *                  isLoading / isError / data / error / reset 等
 */
export const useChangePassword = () =>
	useMutation<TChangePasswordOutput, AxiosError, TChangePasswordInput>({
		mutationKey: ['partner-change-password'],
		mutationFn: async (input) => {
			const res = await changePassword(input)
			return res.data
		},
		retry: 0,
	})
