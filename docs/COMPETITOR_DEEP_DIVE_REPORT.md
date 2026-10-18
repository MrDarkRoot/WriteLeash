# BÁO CÁO NGHIÊN CỨU TOÀN DIỆN MÃ NGUỒN ĐỐI THỦ VÀ HỆ SINH THÁI BULK EDIT WOOCOMMERCE

> **Dự án nghiên cứu:** WriteLeash vs. Competitors  
> **Đối tượng kiểm toán chính:** `bulk-price-editor-for-woocommerce` (u2code, phiên bản `2.5.0` kiểm toán trực tiếp từ WordPress.org SVN trunk, ngày 09/10/2026)  
> **Đối tượng đối chiếu phụ:** WooCommerce Core (`class-wc-admin-post-types.php`), Yoast SEO (`src/bulk-editor/`), WP All Import (`controllers/admin/import.php`).

---

## 1. TỔNG QUAN KIẾN TRÚC MÃ NGUỒN ĐỐI THỦ (u2code v2.5.0)

Toàn bộ mã nguồn plugin của u2code được tổ chức theo chuẩn PSR-4 hiện đại bên dưới namespace `BulkPriceEditor\` với cấu trúc thư mục gồm 8 phân hệ (subsystems):

```
u2code-bulk-price-editor/src/
├── Actions/            # 5 actions xử lý ngầm (Apply, Revert, Schedule, Export CSV, ActionManager)
├── CLI/                # 1 lớp lệnh WP-CLI tích hợp terminal
├── Core/               # ServiceContainer, plugin bootstrap
├── History/            # Quản lý Database Schema, Runs, Snapshots và Price Logger
├── Integrations/       # Tích hợp bên thứ ba (Tiered Pricing Table)
├── PriceEditorPage/    # Admin controller, UI Widgets, Tabs, Toast Notifications
├── PriceModifiers/     # 8 bộ sửa giá (Modifiers) + 1 Math Formula Parser
├── ProductFilters/     # 13 bộ lọc sản phẩm + Filter Template Manager
├── ProductQuery/       # Bộ sinh câu query tìm ID sản phẩm
├── ProductsTable/      # Kế thừa WP_List_Table để vẽ bảng Preview và AJAX
└── Request/            # Wrapper xử lý dữ liệu đầu vào an toàn
```

---

## 2. GIẢI PHẪU CHI TIẾT TỪNG PHÂN HỆ & CHỨC NĂNG CỦA ĐỐI THỦ

### 2.1. Phân hệ Sửa Giá (PriceModifiers Subsystem)
Nằm trong thư mục `src/PriceModifiers/`. Toàn bộ đều kế thừa từ lớp trừu tượng `Modifiers\PriceModifier.php`.

#### Danh sách 8 Modifiers chi tiết:
1. **`NewPrices.php` (Gán giá cố định):**  
   - Cho phép nhập giá Regular và Sale cố định.  
   - Có ô checkbox *"Clear existing sale prices"* để xóa giá sale hàng loạt.
2. **`AddFlatValue.php` (Cộng số tiền):**  
   - Cộng thêm một lượng tiền cố định vào Regular hoặc Sale price (`$oldPrice + $addAmount`).
3. **`SubtractFlatValue.php` (Trừ số tiền):**  
   - Trừ bớt tiền, tự động bọc trong `max(0, ...)` để không bao giờ ra giá âm.
4. **`IncreaseByPercent.php` (Tăng theo %):**  
   - Tăng giá theo tỷ lệ: `$oldPrice + ($oldPrice * $percent / 100)`.
5. **`DecreaseByPercent.php` (Giảm theo %):**  
   - Giảm giá theo tỷ lệ: `max(0, $oldPrice - ($oldPrice * $percent / 100))`.
6. **`RemovePrice.php` (Xóa giá):**  
   - Cho phép chọn xóa riêng Regular Price, xóa Sale Price, hoặc xóa cả hai.
7. **`UpdateByFormula.php` (Sửa bằng công thức - PREMIUM ONLY):**  
   - Cho phép nhập công thức tùy ý dạng text để tính toán cho từng sản phẩm.
8. **`Dispatcher.php`:**  
   - Đóng vai trò Factory nhận `modifier_type` và instantiate lớp modifier tương ứng.

#### 🌟 Điểm sáng kỹ thuật: `PriceFormula.php` (Trình phân tích công thức an toàn)
u2code **tự viết một Recursive-Descent Math Parser** mà **không dùng `eval()`** (tránh lỗ hổng RCE nghiêm trọng):
- **Cú pháp hỗ trợ:** Dấu ngoặc đơn `()`, các toán tử `+`, `-`, `*`, `/`, và 2 hàm toán học `min()`, `max()`.
- **Biến giữ chỗ (Placeholders):** `{regular_price}`, `{sale_price}`, `{price}`, `{cost}`.
- **Cơ chế an toàn:**  
  1. Giới hạn độ dài tối đa 200 ký tự.  
  2. Dùng regex kiểm tra ký tự hợp lệ: `/^(?:[0-9+\-*\/().,\s]|min|max)+$/i`.  
  3. Nếu sản phẩm thiếu bất kỳ biến nào (ví dụ sản phẩm không có `{cost}`), hàm trả về `null` ngay lập tức để an toàn bỏ qua sản phẩm đó.
- **Ví dụ công thức thực tế:**  
  `max({cost} * 1.25, {regular_price} * 0.8)`  
  *(Đảm bảo giá sale giảm 20% nhưng không bao giờ thấp hơn mức lãi 25% trên giá vốn).*

#### 🌟 Cơ chế làm tròn số đuôi tâm lý học (`PriceModifier::applyRounding`)
Họ xử lý đuôi giá `.00`, `.90`, `.95`, `.99` bằng thuật toán cực kỳ thông minh trong file `PriceModifier.php` (dòng 94–106):
```php
$cents = (int) $rounding / 100; // ví dụ 99 / 100 = 0.99
$value = round( (float) $price - $cents ) + $cents;
```
*Ví dụ:* Giá tính ra `$18.42`, làm tròn đuôi `.99` ➔ `$18.42 - 0.99 = 17.43`, `round(17.43) = 17`, cộng lại `0.99` ➔ **`$17.99`**.

---

### 2.2. Phân hệ Bộ Lọc Sản Phẩm (ProductFilters Subsystem)
Nằm trong `src/ProductFilters/`. Quản lý bởi `FilterManager.php`.

#### 13 Bộ lọc sản phẩm cực kỳ phong phú:
1. **`IncludedProducts.php`:** Chọn danh sách sản phẩm chỉ định cụ thể.
2. **`ExcludedProducts.php`:** Chọn loại trừ một số sản phẩm (dù chúng nằm trong danh mục đang chọn).
3. **`SKUAndName.php` (PREMIUM):** Lọc theo chuỗi SKU hoặc từ khóa tên sản phẩm.
4. **`ProductType.php`:** Lọc Simple, Variable, Variation, External.
5. **`TermsFilter.php`:** Lọc theo Danh mục sản phẩm (Categories). Hỗ trợ logic: chọn cha tự động gom cả các con.
6. **`FilterByTags.php` (PREMIUM):** Lọc theo thẻ tag sản phẩm.
7. **`FilterByAttributes.php` (PREMIUM):** Lọc theo thuộc tính biến thể (ví dụ: chỉ đổi giá các áo có `pa_size = XL` hoặc `pa_color = Red`).
8. **`FilterByBrands.php` (PREMIUM):** Tích hợp lọc theo thương hiệu sản phẩm (hỗ trợ các plugin Brand phổ biến).
9. **`FilterByShippingClass.php` (PREMIUM):** Lọc theo nhóm phí vận chuyển (Shipping class).
10. **`PriceRange.php`:** Lọc sản phẩm có giá nằm trong khoảng từ `Min` đến `Max` (áp dụng cho cả Regular hoặc Sale).
11. **`StockStatus.php` (PREMIUM):** Lọc theo tình trạng kho: Còn hàng (In stock), Hết hàng (Out of stock), Cho phép đặt trước (Backorder).
12. **`FeaturedStatus.php` (PREMIUM):** Lọc các sản phẩm được đánh dấu "Nổi bật" (Featured).
13. **`DateRange.php` (PREMIUM):** Lọc theo ngày tạo (Date added) hoặc ngày sửa đổi gần nhất (Date modified).

#### 🌟 Điểm sáng UX: `TemplateManager.php` (Lưu mẫu tác vụ để tái sử dụng)
- Cho phép lưu trọn gói: `[Danh sách bộ lọc] + [Phép tính giá]` thành một **Template có tên riêng**.
- Lưu trữ trong bảng `wp_options` dưới khóa `bpe_templates`.
- Merchant có thể tạo các mẫu như: *"Khuyến mãi Áo thun mùa hè"*, *"Xả kho hàng tồn"*. Lần sau mở plugin chỉ cần chọn Template và bấm Apply trong 5 giây.

---

### 2.3. Phân hệ Nhật Ký Biến Động Giá (History & Audit Trail Subsystem)
Nằm trong `src/History/`. Đây là phân hệ có giá trị thực tiễn cao nhất.

#### Cấu trúc 3 Bảng Database tùy chỉnh (`Schema.php`):
1. **`wp_bpe_runs` (Quản lý các đợt chạy bulk):**  
   Lưu `id`, `type` (`update` hoặc `revert`), `status`, `user_id`, `modifier_type`, `modifier_args`, `filters`, `total`, `processed`, `scheduled_at`, `revert_at`.
2. **`wp_bpe_run_prices` (Snapshot để Revert):**  
   Lưu `run_id`, `product_id`, `old_regular`, `old_sale`, `new_regular`, `new_sale`, `old_sale_from`, `old_sale_to`, `new_sale_from`, `new_sale_to`.
3. **`wp_bpe_price_log` (Bảng Audit Trail per-product):**  
   Lưu `product_id`, `changed_at`, `old_regular`, `new_regular`, `old_sale`, `new_sale`, `source` (`bulk_update`, `manual`, `cron`, `rest_api`), `source_id`, `user_id`.

#### 🌟 Kỹ thuật bắt log toàn diện (`PriceLogger.php`):
Thay vì bắt ở hook `save_post` (vốn hay bị bỏ qua bởi API hoặc script), họ bắt trực tiếp vào tầng WordPress Meta:
```php
add_action( 'add_post_meta', array( self::class, 'onAddMeta' ), 10, 3 );
add_action( 'update_post_meta', array( self::class, 'onUpdateMeta' ), 10, 4 );
add_action( 'delete_post_meta', array( self::class, 'onDeleteMeta' ), 10, 4 );
```
Nhờ vậy, **bất kỳ nguồn nào làm thay đổi giá sản phẩm trên web đều bị ghi vết lại 100%**:
- Nếu đang chạy Bulk Update ➔ `source = 'bulk_update'` kèm `run_id`.
- Nếu có ai gọi REST API từ máy POS ➔ `source = 'rest_api'`.
- Nếu chạy WP-Cron ➔ `source = 'cron'`.
- Nếu admin sửa tay trong wp-admin ➔ `source = 'manual'` kèm `user_id`.

#### 🌟 Giao diện "Price History Modal" (`PriceLogProductScreen.php`):
Họ cắm một nút bấm nhỏ ngay trong trang sửa sản phẩm WooCommerce:
```php
add_action( 'woocommerce_product_options_pricing', array( self::class, 'renderProductLink' ) );
add_action( 'woocommerce_variation_options_pricing', array( self::class, 'renderVariationLink' ), 10, 3 );
```
Hiển thị liên kết: **`Price history (3)`**. Khi bấm vào, một modal AJAX mở ra hiển thị danh sách 3 lần đổi giá gần nhất, ai đổi, từ bao nhiêu thành bao nhiêu, vào lúc mấy giờ.

---

### 2.4. Phân hệ Xử Lý Chạy Ngầm & Lên Lịch (Actions Subsystem)
Nằm trong `src/Actions/`.

1. **`UpdateProductPriceAction.php`:**  
   - Nhận mảng 100 product IDs.  
   - Đọc snapshot cũ nếu đây là lượt thử lại (retry) để tránh nhân % hai lần.  
   - Chạy `$priceModifier->updatePrices($product)`.  
   - Tăng biến đếm `processed` trong bảng `bpe_runs`. Khi hoàn thành thì đánh dấu `status = 'completed'`.
2. **`SchedulePriceUpdatesAction.php` (PREMIUM):**  
   - Cho phép đặt 2 mốc thời gian: `scheduled_at` (bắt đầu sale) và `revert_at` (kết thúc sale).  
   - Dùng Action Scheduler để kích hoạt `UpdateProductPriceAction` khi đến giờ bắt đầu, và kích hoạt `RevertPricesAction` khi đến giờ kết thúc.
3. **`RevertPricesAction.php` (PREMIUM):**  
   - Khôi phục giá cũ từ bảng snapshot. Tạo ra một Run mới mang type `revert`.  
   - *Hạn chế (đã kiểm toán):* Không kiểm tra conflict, ghi đè giá cũ lên bất kỳ chỉnh sửa nào mới hơn.
4. **`ExportCsvAction.php`:**  
   - Xuất dữ liệu bảng Preview ra file CSV (gồm: ID, Tên, SKU, Giá cũ, Giá mới, Chênh lệch) trước khi bấm duyệt.

---

### 2.5. Phân hệ Bảng Preview & Giao Diện (ProductsTable Subsystem)
Nằm trong `src/ProductsTable/`.

- Kế thừa trực tiếp lớp `WP_List_Table` chuẩn của WordPress Core (`WPListProductsTable.php`).
- Hỗ trợ đầy đủ các cột: Ảnh thumbnail (`ImageColumn`), Tên SP (`TitleColumn`), Danh mục (`CategoriesColumn`), Loại SP (`ProductTypeColumn`), Tình trạng kho (`StockStatusColumn`), Giá cũ, Giá mới, Ngày cập nhật.
- Sử dụng AJAX (`AjaxHandler.php`) để tải và phân trang Preview mượt mà mà không cần tải lại toàn bộ trang admin.

---

### 2.6. Phân hệ Dòng Lệnh (WP-CLI Subsystem)
Nằm trong `src/CLI/Commands.php`. Cung cấp 4 lệnh terminal chuẩn chỉ:
```bash
wp bulk-price-editor templates              # Liệt kê các mẫu công việc đã lưu
wp bulk-price-editor run <template-slug>    # Chạy đổi giá từ dòng lệnh
wp bulk-price-editor history                # Xem lịch sử các đợt chạy gần nhất
wp bulk-price-editor revert <run-id>        # Hoàn tác một đợt chạy từ terminal
```

---

## 3. BÀI HỌC TỪ CÁC PLUGIN KHÁC TRONG HỆ SINH THÁI (`targets/wordpress`)

### 3.1. WooCommerce Core (`class-wc-admin-post-types.php`)
- **Điểm yếu của Core:**  
  1. Ép kiểu số thực `(float)` trực tiếp, dễ dính sai số làm tròn.  
  2. Chạy đồng bộ trong HTTP POST request, nếu chọn quá nhiều sản phẩm thì hosting bị timeout 504.  
  3. Không có Preview trước khi lưu. Không có History và Undo.  
- **Cơ hội:** Đây chính là lý do vì sao thị trường luôn cần các plugin chuyên biệt như WriteLeash hay u2code.

### 3.2. Yoast SEO (`src/bulk-editor/`)
- Yoast áp dụng kiến trúc **Domain-Driven Design (DDD)** rất mẫu mực:  
  Họ chia thành các Entity: `Post_Update`, tập hợp `Post_Update_Collection`, và kết quả `Update_Result_Collection`.  
- Tách rời hoàn toàn tầng dữ liệu (`Meta_Writer_Interface`) khỏi tầng nghiệp vụ. Đây là kiến trúc rất đáng học hỏi khi WriteLeash mở rộng quy mô.

### 3.3. WP All Import (`controllers/admin/import.php`)
- **Cơ chế Cron Key:** WP All Import dùng một chuỗi bí mật `cron_job_key` trên URL để cho phép các dịch vụ cron bên ngoài (như EasyCron) gọi kích hoạt mà không bị lộ quyền quản trị.
- **Chunk Memory Release:** Giữa các đợt xử lý chunk lớn, họ chủ động gọi hàm dọn dẹp cache `clean_post_cache()` và giải phóng bộ nhớ PHP để chống tràn RAM.

---

## 4. CHIẾN LƯỢC BÁN HÀNG CỦA ĐỐI THỦ (Freemium Paywall Analysis)

Đối thủ u2code kiếm tiền cực kỳ bài bản bằng cách **tạo ra bản Free đủ dùng để thu hút người dùng, nhưng khóa đúng những "điểm nghẽn" quan trọng nhất để thu $69/năm**:

| Chức năng | Bản Free (u2code) | Bản Premium ($69/năm) | Ghi chú tâm lý học người dùng |
| :--- | :---: | :---: | :--- |
| **Lọc sản phẩm** | Chỉ lọc theo Category | **Lọc theo SKU, Tên, Tag, Kho, Brand** | Shop lớn có hàng ngàn SKU buộc phải mua Pro vì không thể lọc bằng tay theo category. |
| **Sửa Giá** | Chỉ sửa Regular Price | **Sửa Sale Price, Dùng Công thức (Formula)** | Muốn chạy khuyến mãi giảm giá bắt buộc phải mua Pro. |
| **An toàn** | Chỉ xem Preview | **Nút Revert, Hẹn giờ tự động hoàn tác** | Đánh vào tâm lý sợ sai giá để bán tính năng Revert. |
| **Tiện ích** | Không có | **Làm tròn đuôi .99 / .95, WP-CLI** | Bán cho các agency và người làm marketing chuyên nghiệp. |

---

## 5. BẢN ĐỒ CẢI TIẾN CHIẾN LƯỢC CHO WRITELEASH (Actionable Blueprint)

Từ toàn bộ các dữ liệu mã nguồn đã kiểm toán, đây là lộ trình cụ thể để biến WriteLeash thành một sản phẩm vượt trội:

```mermaid
flowchart TD
    subgraph Giai đoạn 1: Quick Wins (Bắt bài đối thủ)
        A1["1. Triển khai Làm tròn .99 (#208)<br/>(Dùng thuật toán 2 dòng của u2code)"]
        A2["2. Thêm thao tác Clear Sale (#207)<br/>(Học cách NewPrices/RemovePrice xử lý rỗng)"]
        A3["3. Bọc text đa ngôn ngữ i18n (#210)<br/>(Sẵn sàng nộp WordPress.org)"]
    end

    subgraph Giai đoạn 2: Nâng cấp Trải nghiệm (Usability Leap)
        B1["4. Filter Templates (Lưu mẫu tác vụ)<br/>(Lưu mảng filter + operation vào options)"]
        B2["5. Price Audit Link per Product<br/>(Bắt hook update_post_meta để vẽ link 'Price History')"]
        B3["6. CSV Preview Export<br/>(Cho phép tải CSV bảng Preview trước khi duyệt)"]
    end

    subgraph Giai đoạn 3: Vũ khí Bản Pro (Unfair Advantages)
        C1["7. Safe Formula Engine<br/>(Parser như u2code nhưng dùng String Math của WriteLeash)"]
        C2["8. Conflict-Safe Scheduled Flash Sales<br/>(Hẹn giờ sale + Auto Revert có kiểm tra xung đột)"]
        C3["9. WP-CLI Command Suite<br/>(wp writeleash run / revert / resume / history)"]
    end

    Giai đoạn 1 --> Giai đoạn 2 --> Giai đoạn 3
```

### 3 "Đòn bẩy" giúp WriteLeash đánh bại đối thủ:
1. **Bản Free của WriteLeash vượt trội hơn:**  
   WriteLeash cho sẵn lọc theo SKU và nút Undo an toàn ở bản Free. Đây là 2 thứ mà đối thủ bắt trả $69 mới có. Mang điểm này ra làm thông điệp truyền thông trên WordPress.org sẽ thu hút người dùng cực kỳ nhanh.
2. **Cơ chế Revert an toàn độc quyền:**  
   u2code Revert mù quáng và có thể đè bẹp các thay đổi mới của nhân viên. WriteLeash có cơ chế **Conflict-Aware Undo** (chỉ khôi phục khi giá chưa bị ai sửa tiếp). Đây là tính năng bảo vệ dữ liệu chuẩn mực duy nhất trên thị trường.
3. **Độ chính xác số học tuyệt đối:**  
   Toàn bộ tính toán của WriteLeash dùng chuỗi số (`Price_Decimal`), trong khi đối thủ dùng số thực float. Với các cửa hàng bán hàng ngàn sản phẩm, độ chính xác không sai 1 xu là cam kết thương hiệu rất mạnh mẽ.
