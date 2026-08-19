"use client"

import * as React from "react"
import * as DropdownMenuPrimitive from "@radix-ui/react-dropdown-menu"

import { cn } from "@/lib/utils"

/**
 * 「押すと実行されるアクションの一覧」を出すメニュー。
 * 値を選んで保持する用途（フォーム部品）は select.tsx を使う。
 *
 * 実際に使う Root / Trigger / Content / Item のみを公開する（popover.tsx と同じ粒度）。
 * Sub / RadioGroup / CheckboxItem などは必要になった時点で追加する。
 */
const DropdownMenu = DropdownMenuPrimitive.Root

const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger

const DropdownMenuContent = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Content>
>(({ className, align = "start", sideOffset = 4, ...props }, ref) => (
  <DropdownMenuPrimitive.Portal>
    <DropdownMenuPrimitive.Content
      ref={ref}
      align={align}
      sideOffset={sideOffset}
      className={cn(
        // 見た目は popover.tsx に揃える（同じ「一覧の上に出るパネル」で印象を割らないため）。
        // Select と違い内部スクロールを自前で持たないため、件数が増えても画面外へ伸びないよう max-h を既定にする。
        "z-50 max-h-[60vh] overflow-y-auto rounded-md border border-input bg-white p-1.5 text-foreground shadow-md outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95",
        className
      )}
      {...props}
    />
  </DropdownMenuPrimitive.Portal>
))
DropdownMenuContent.displayName = DropdownMenuPrimitive.Content.displayName

const DropdownMenuItem = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Item>
>(({ className, ...props }, ref) => (
  <DropdownMenuPrimitive.Item
    ref={ref}
    className={cn(
      // MultiSelectDropdown（Popover + Checkbox）の項目と同じ余白・文字サイズにする。
      // マウスホバーもキーボード移動も Radix が data-highlighted に集約するため focus: ではなくそちらを見る。
      "flex cursor-pointer select-none items-center gap-2 rounded px-3 py-1.5 text-xs outline-none data-[highlighted]:bg-muted/50 data-[disabled]:pointer-events-none data-[disabled]:cursor-default data-[disabled]:text-muted-foreground",
      className
    )}
    {...props}
  />
))
DropdownMenuItem.displayName = DropdownMenuPrimitive.Item.displayName

export {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
}
