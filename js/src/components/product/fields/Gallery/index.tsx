import { PlusOutlined, EyeOutlined, CloseOutlined } from '@ant-design/icons'
import { Image, Form } from 'antd'
import { SizeType } from 'antd/es/config-provider/SizeContext'
import { defaultImage, cn } from 'antd-toolkit'
import {
	TImage,
	MediaLibraryModal,
	useMediaLibraryModal,
} from 'antd-toolkit/wp'
import { nanoid } from 'nanoid'

import { TFormValues } from '@/components/product/ProductEditTable/types'

const { Item } = Form

export const Gallery = ({
	id,
	size,
	limit = 10,
	wrapClassName = 'flex flex-wrap gap-2',
	onValuesChange,
	name,
	shape = 'square',
	title = '商品圖片',
	allowSetThumbnail = true,
}: {
	id?: string
	size?: SizeType
	limit?: number
	wrapClassName?: string
	onValuesChange?: (
		changedValues: {
			[key: string]: Partial<TFormValues>
		},
		allValues: TFormValues[]
	) => void

	/** 自訂 form 欄位路徑；不傳時沿用商品表單慣例 `['images']` / `[id, 'images']` */
	name?: (string | number)[]

	/** 圖片外框形狀，`circle` 用於人物頭像類情境（例：分潤夥伴圖片） */
	shape?: 'square' | 'circle'

	/** 區塊標題文字（`size="small"` 時不顯示） */
	title?: string

	/** 是否顯示「設為封面」操作；單張情境（limit=1）應關閉 */
	allowSetThumbnail?: boolean
}) => {
	const imageName = name ?? (id ? [id, 'images'] : ['images'])
	const form = Form.useFormInstance()

	// 初始的圖片
	const watchImages: TImage[] = Form.useWatch(imageName, form)

	// 欄位尚未被賦值時 useWatch 會回 undefined；統一收斂成陣列，
	// 否則 `watchImages?.length < limit` 會是 `undefined < n` = false，導致「+」按鈕不出現
	const images: TImage[] = watchImages ?? []

	const { show, close, modalProps, ...mediaLibraryProps } =
		useMediaLibraryModal({
			initItems: images,
			onConfirm: (selectedItems) => {
				// 媒體庫的 limit 只約束 UI，這裡再截斷一次確保寫進 form 的數量不超過上限
				const nextImages = (selectedItems ?? []).slice(0, limit)
				form.setFieldValue(imageName, nextImages)

				if (onValuesChange && id) {
					const allValues = form.getFieldsValue()
					onValuesChange?.(
						{
							[id]: {
								//@ts-ignore
								images: nextImages,
							},
						} as {
							[key: string]: Partial<TFormValues>
						},
						allValues
					)
				}
			},
		})

	/** 移除 form 圖片 */
	const handleRemove = (_imageId: string) => () => {
		form.setFieldValue(
			imageName,
			images.filter(({ id: imageId }) => imageId !== _imageId)
		)

		if (onValuesChange && id) {
			const allValues = form.getFieldsValue()
			onValuesChange?.(
				{
					[id]: {
						//@ts-ignore
						images: images.filter(({ id: imageId }) => imageId !== _imageId),
					},
				} as {
					[key: string]: Partial<TFormValues>
				},
				allValues
			)
		}
	}

	/** 設定封面 */
	const handleSetThumbnail = (targetId: string) => () => {
		// 移動到第一個
		const resortImages: TImage[] = images.reduce((acc, image) => {
			if (image.id === targetId) {
				acc.unshift(image)
			} else {
				acc.push(image)
			}
			return acc
		}, [] as TImage[])

		form.setFieldValue(imageName, resortImages)
	}

	const imgSizeClass = 'small' === size ? 'w-10 h-10' : 'w-24 h-24'
	const shapeClass = 'circle' === shape ? 'rounded-full' : 'rounded-lg'

	// 單張情境沒有「封面」概念（唯一一張本來就是封面），關閉可避免誤導文案
	const showThumbnailAction = allowSetThumbnail && limit > 1

	return (
		<>
			{'small' !== size && (
				<span className="text-sm font-normal inline-block pb-2">{title}</span>
			)}
			<Item name={imageName} hidden />
			<div className={wrapClassName}>
				{images.map(
					({ id: _imageId, url }, index) =>
						index < limit && (
							<Image
								key={`${_imageId}-${nanoid(4)}`}
								className={cn(
									'product-image aspect-square object-cover',
									shapeClass,
									imgSizeClass
								)}
								preview={{
									mask: (
										<div className="flex flex-col items-center justify-center">
											<div>
												<EyeOutlined />
												<CloseOutlined
													className={'small' !== size ? 'ml-2' : 'ml-1'}
													onClick={handleRemove(_imageId)}
												/>
											</div>
											{'small' !== size && showThumbnailAction && (
												<span
													className="m-0 text-xs"
													role="button"
													tabIndex={0}
													onClick={handleSetThumbnail(_imageId)}
													onKeyDown={(e) => {
														if (e.key === 'Enter' || e.key === ' ') {
															handleSetThumbnail(_imageId)()
														}
													}}
												>
													{index === 0 ? '封面' : '設為封面'}
												</span>
											)}
										</div>
									),
									maskClassName: shapeClass,
								}}
								src={url || defaultImage}
								fallback={defaultImage}
							/>
						)
				)}
				{images.length < limit && (
					<div
						className={cn(
							'group aspect-square cursor-pointer bg-gray-100 hover:bg-blue-100 border-dashed border-2 border-gray-200 hover:border-blue-200 transition-all duration-300 flex justify-center items-center',
							shapeClass,
							imgSizeClass
						)}
						role="button"
						tabIndex={0}
						onClick={show}
						onKeyDown={(e) => {
							if (e.key === 'Enter' || e.key === ' ') {
								show()
							}
						}}
					>
						<PlusOutlined className="text-gray-500 group-hover:text-blue-500 transition-all duration-300" />
					</div>
				)}
			</div>
			<MediaLibraryModal
				modalProps={modalProps}
				mediaLibraryProps={{
					...mediaLibraryProps,
					limit,
					uploadProps: {
						accept: 'image/*',
					},
				}}
			/>
		</>
	)
}
