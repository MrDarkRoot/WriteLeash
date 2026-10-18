# 📘 BÁO CÁO NGHIÊN CỨU TOÀN DIỆN CODEBASE: WRITELEASH FOR WOOCOMMERCE v0.1.0
**Mốc kiểm toán:** Sau khi hợp nhất PR #202 (UX Refinement) và PR #203 (Release Packaging #194)  
**Commit HEAD:** `c48e307`  
**Phạm vi áp dụng (Scope):** `wordpress/writeleash/` (WordPress Plugin Product) kèm tài liệu phân phối `wordpress/release/` và tài nguyên hiển thị `wordpress/assets/`  
**Đối tượng tham chiếu:** Solo Founder / Tech Lead  

---

## MỤC LỤC
1. [Tổng quan dự án & Vị thế sản phẩm](#1-tổng-quan-dự-án--vị-thế-sản-phẩm)
2. [Kiến trúc kỹ thuật cốt lõi (Core Engine Architecture)](#2-kiến-trúc-kỹ-thuật-cốt-lõi-core-engine-architecture)
3. [Hiện trạng sau hai mốc nâng cấp lớn: PR #202 và PR #203](#3-hiện-trạng-sau-hai-mốc-nâng-cấp-lớn-pr-202-và-pr-203)
4. [Tuân thủ tiêu chuẩn WordPress.org & Kết quả Plugin Check](#4-tuân-thủ-tiêu-chuẩn-wordpressorg--kết-quả-plugin-check)
5. [Toàn bộ danh mục vấn đề & Điểm nghẽn dành cho Solo Founder](#5-toàn-bộ-danh-mục-vấn-đề--điểm-nghẽn-dành-cho-solo-founder)
6. [Định vị sản phẩm & Thông điệp bán hàng (Positioning & Marketing)](#6-định-vị-sản-phẩm--thông-điệp-bán-hàng-positioning--marketing)
7. [Checklist phát hành & Lộ trình phiên bản tiếp theo (Roadmap)](#7-checklist-phát-hành--lộ-trình-phiên-bản-tiếp-theo-roadmap)

---

## 1. TỔNG QUAN DỰ ÁN & VỊ THẾ SẢN PHẨM

### 1.1. Ranh giới sản phẩm (Product Boundary)
- **Dự án mẹ (WriteLeash Core):** Dự án nghiên cứu khoa học chuyên sâu về bảo vệ an toàn cơ sở dữ liệu (Database Mutation Authority / Transactional Budgets).
- **Sản phẩm thương mại đầu tiên:** Plugin WordPress/WooCommerce nằm trọn vẹn trong thư mục `wordpress/writeleash/`.
- **Mục tiêu sản phẩm:** Cung cấp công cụ đổi giá hàng loạt (Bulk Price Editor) có tính năng xem trước chính xác (Preview), tự động phát hiện và bảo vệ các chỉnh sửa mới hơn (Concurrency Protection / Conflict Awareness), xử lý chạy nền an toàn (Resumable Background Processing) và cho phép hoàn tác có điều kiện (Safe Undo).

### 1.2. Môi trường kiểm thử mục tiêu (Target Environment)
- **WordPress:** Tối thiểu 7.0, tương thích đã xác thực đến **WordPress 7.1.3**.
- **WooCommerce:** Hỗ trợ chính thức từ **WooCommerce 10.0 đến 11.x** (tối thiểu 10.0.0, dưới 12.0.0).
- **PHP:** Yêu cầu tối thiểu PHP 7.4 (khuyến nghị PHP 8.2+).
- **Cơ sở dữ liệu:** MySQL 8.0+ hoặc MariaDB 10.11+ có hỗ trợ InnoDB engine và giao dịch ACID (`mysqli` driver).

---

## 2. KIẾN TRÚC KỸ THUẬT CỐT LÕI (CORE ENGINE ARCHITECTURE)

WriteLeash khác biệt hoàn toàn với các plugin chỉnh sửa hàng loạt truyền thống nhờ kiến trúc phòng thủ dữ liệu sâu (Defense-in-depth):

```
[Chủ shop thao tác]
        │
        ▼
[1. Chọn sản phẩm] ──► (ID list, 1 Danh mục trực tiếp, 1 SKU chính xác, Manual IDs)
        │
        ▼
[2. Lập kế hoạch (Change Plan)] ──► Tính toán số học thập phân không làm tròn số float
        │
        ▼
[3. Xem trước (Preview Snapshot)] ──► Đóng băng trạng thái hiện tại & Tạo Plan Hash SHA256
        │
        ▼
[4. Duyệt & Chạy ngầm (Approve & Background Job)]
        ├── Cơ chế Phân tán Khóa (Fenced Lease TTL 60s)
        ├── Khóa bản ghi DB mức hàng: SELECT ... FOR UPDATE
        ├── Kiểm tra xung đột: Nếu giá trong Woo bị đổi sau lúc Preview ──► BỎ QUA (CONFLICT)
        └── Ghi nhật ký từng bước: Bảng Journal bền vững (Durable Journal)
        │
        ▼
[5. Hoàn tác an toàn (Safe Undo)]
        └── Kiểm tra Fingerprint SHA256: Chỉ hoàn tác sản phẩm chưa bị sửa đổi thủ công sau đó
```

### Các lớp thành phần cốt lõi:
1. **`Woo_Price_Mutator` & `Price_Apply_Transaction_Guard`:**
   - Đảm bảo mỗi lần cập nhật giá đều nằm trong một Database Transaction độc lập.
   - Sử dụng khóa hàng InnoDB (`FOR UPDATE`) để ngăn chặn hoàn toàn hiện tượng Race Condition (hai tiến trình cùng ghi đè một lúc).
2. **`Price_Decimal`:**
   - Xử lý số học thập phân (cộng, trừ, nhân tỉ lệ %) bằng thuật toán chuỗi số nguyên lớn, tuyệt đối không dùng kiểu số thực `float` của PHP nhằm triệt tiêu lỗi trôi dấu phẩy động (Floating-point precision loss).
3. **`Job_Worker` & `Job_Transaction_Fence`:**
   - Chia nhỏ khối lượng công việc thành từng đợt (chunks, mặc định 10 sản phẩm/batch) xử lý qua Action Scheduler.
   - Áp dụng mô hình Hợp đồng Khóa (Distributed Lease): nếu worker gặp sự cố mạng hoặc timeout, khóa sẽ tự giải phóng sau 60 giây và cho phép chủ shop bấm nút **"Resume remaining products"** mà không làm mất tiến trình đã lưu.
4. **`Undo_Fingerprint`:**
   - Mỗi sản phẩm đổi giá thành công đều được băm SHA256 kèm dấu vết giá trước và sau.
   - Khi chạy Hoàn tác (Undo), nếu phát hiện sản phẩm đã bị người quản trị khác sửa giá tay hoặc ERP cập nhật giá mới trong WooCommerce, WriteLeash sẽ **giữ nguyên giá mới nhất** và đánh dấu `CONFLICT`, từ chối ghi đè thô bạo.

---

## 3. HIỆN TRẠNG SAU HAI MỐC NÂNG CẤP LỚN: PR #202 VÀ PR #203

### 3.1. Những cải tiến từ PR #202 (Trải nghiệm người dùng - UX)
- **Bộ nhận diện thương hiệu:** Bổ sung `admin-logo.png` (hiển thị 64×64px) đặt ngay cạnh tiêu đề trang quản trị.
- **Form chọn lựa thông minh (Dynamic Selector):**
  - Tự động ẩn các ô nhập liệu không liên quan; chỉ hiển thị đúng ô tìm kiếm, danh mục hoặc SKU theo lựa chọn của người dùng.
  - Tự động mở rộng thẻ `<details>` khi chọn SKU hoặc Manual IDs.
- **Nhãn số tiền biến đổi theo ngữ cảnh (Contextual Amount Labels):**
  - Tự đổi thành "New price" khi chọn SET.
  - Tự đổi thành "Percentage (e.g. 8 for 8%)" khi chọn phép tính phần trăm.
  - Tự đổi thành "Amount to increase/decrease by" khi tăng/giảm tiền cố định.
- **Làm gọn giao diện & Chấn an người dùng:**
  - Thu gọn toàn bộ rào cản an toàn (Safety Limits) vào thẻ `<details>` để không gây hoang mang cho người dùng mới.
  - Đổi câu nhắc tại nút xem trước thành: *"Preview does not change any prices."* (ngắn gọn, giải tỏa tâm lý sợ bấm nhầm đổi giá).
  - Làm rõ ngữ nghĩa của nút Resume và Undo.

### 3.2. Những chuẩn hóa từ PR #203 (Đóng gói phát hành - Packaging #194)
- **Vá bảo mật chuẩn WordPress:** Thêm `sanitize_text_field( wp_unslash( $input['nonce'] ) )` trong AJAX endpoint `class-product-discovery.php`.
- **Cập nhật script đóng gói `build-wordpress-org.py`:**
  - Ghim chính xác mã nguồn runtime `6f8ae7d...`.
  - Cập nhật danh sách manifest chuẩn **42 files** (bao gồm `admin-logo.png`).
  - Cập nhật regex kiểm tra cam kết hỗ trợ WooCommerce trong `readme.txt` đồng bộ với test suite.
- **Tạo lập hồ sơ nghiệm thu chính thức:** Xuất bản tài liệu `SUBMISSION-194.md` cùng bằng chứng JSON `submission-194-results.json` và `artifact-194-evidence.json`.

---

## 4. TUÂN THỦ TIÊU CHUẨN WORDPRESS.ORG & KẾT QUẢ PLUGIN CHECK

Theo báo cáo kiểm định chính thức trong `wordpress/release/SUBMISSION-194.md`:

### 4.1. Thông số gói cài đặt ứng viên (Candidate Package)
- **Tên file:** `writeleash-0.1.0.zip`
- **Dung lượng:** `1,878,448 bytes` (~1.8 MB, thấp hơn rất nhiều so với trần 10 MB của WordPress.org).
- **Mã băm SHA256:** `b8bd5f406667f6f686348ea67453ec25b90e09607cd9a13b542e6054c9f8a3e8`
- **Số lượng file:** Chính xác **42 files** sạch nằm trong thư mục gốc `writeleash/`, không chứa bất kỳ file test, tài liệu nội bộ, git, docker hay artifact nào.

### 4.2. Kết quả Plugin Check 2.1.0 (Official Tool)
- **Exit Code:** `0` (Đạt chuẩn nộp).
- **Ghi nhận 8 cảnh báo (Warnings/Errors):** Tất cả đều nằm trong `includes/free/class-price-apply-journal.php` liên quan đến việc kiểm tra cấu trúc bảng (`SHOW COLUMNS`, `$wpdb->query`) để tự động nâng cấp bảng journal riêng của plugin.
- **Kết luận thẩm định:** Toàn bộ 8 điểm này là **REVIEWED FALSE POSITIVE** (ngoại lệ kỹ thuật chính đáng), bảng thuộc sở hữu riêng của plugin, tham số đã được bọc bind identifier `%i` an toàn, không có lỗ hổng SQL Injection.

### 4.3. Kết quả Readme Validator & Artwork
- **Official Readme Validator:** **PASS 100%**, 0 lỗi, 0 cảnh báo.
- **Thư mục Assets (`wordpress/assets/`):** Đầy đủ 11 tài nguyên đạt chuẩn kích thước pixel:
  - `icon-128x128.png`, `icon-256x256.png`, `icon.svg`
  - `banner-772x250.png`, `banner-1544x500.png`
  - `screenshot-1.png` đến `screenshot-6.png` khớp với 6 mục mô tả trong `readme.txt`.

---

## 5. TOÀN BỘ DANH MỤC VẤN ĐỀ & ĐIỂM NGHẼN DÀNH CHO SOLO FOUNDER

Khi vận hành một plugin đơn độc (solo), founder phải xử lý mọi khâu từ code đến hỗ trợ kỹ thuật. Dưới đây là bức tranh thực tế về các vấn đề bạn sẽ đối mặt:

### 5.1. Vấn đề Tiền phát hành (Pre-release)
- **Tình trạng:** **ĐÃ GIẢI QUYẾT 100%.**
- Toàn bộ các bài kiểm tra `pr-fast.sh` đều PASS. Gói ZIP candidate đã sẵn sàng để tải lên trang nộp của WordPress.org.

### 5.2. Điểm nghẽn Trải nghiệm & Hỗ trợ khách hàng (Post-release Support Traps)
Sau khi plugin được duyệt và có lượt cài đặt, đây là những câu hỏi bạn sẽ nhận được nhiều nhất:

1. **"Plugin bị treo / đứng hình?" (Do thiếu thanh tiến trình tự động AJAX):**
   - *Thực tế:* WriteLeash xử lý ngầm qua Action Scheduler. Nhưng giao diện Admin không có cơ chế polling tự động, người dùng phải bấm F5 thủ công để thấy số lượng cập nhật.
   - *Cách xử lý của founder:* Giao diện đã có câu hướng dẫn *"Reload this page to check progress"*. Lên kế hoạch thêm tính năng tự động làm mới (live polling) trong bản v0.2.
2. **"Tại sao danh mục con không được đổi giá?" (Direct members only):**
   - *Thực tế:* WriteLeash chỉ chọn sản phẩm gắn trực tiếp vào danh mục cha, không tự đệ quy qua các danh mục con.
   - *Cách xử lý của founder:* Giao diện ở PR #202 đã có dòng nhắc rõ ràng bằng tiếng Anh. Hãy dùng điểm này để giải thích trong forum hỗ trợ khi khách hỏi.
3. **"Làm sao làm tròn giá về đuôi .99 / .95?":**
   - *Thực tế:* WriteLeash tính toán chính xác số học thập phân (giảm 15% của $19.99 ra $16.9915). Chưa có quy tắc làm tròn giá tâm lý (Charm Pricing).
   - *Cách xử lý của founder:* Giải thích trong FAQ rằng v0.1 tập trung vào an toàn dữ liệu, tính năng làm tròn giá nằm trong kế hoạch của v0.2.
4. **"Tôi có 5.000 sản phẩm, làm sao sửa một lần?":**
   - *Thực tế:* Plugin giới hạn cứng 1.000 sản phẩm/job để đảm bảo không tràn RAM và tránh khóa bảng MySQL quá lâu.
   - *Cách xử lý của founder:* Hướng dẫn khách chia nhỏ theo từng danh mục hoặc từng đợt 1.000 món. Nhấn mạnh đây là rào chắn an toàn bảo vệ cửa hàng của họ.

### 5.3. Khả năng bảo trì mã nguồn dài hạn (Maintainability)
- **Kích thước file `class-free-admin.php` (1.654 dòng):**
  - Class này hiện đóng nhiều vai trò (Menu, Router, POST Handlers, Render Views, Export CSV).
  - *Lời khuyên:* **Không refactor lúc này.** Code đã được test bao phủ rất kỹ. Chỉ nên tách nhỏ thành `Admin_View` và `Admin_Handler` ở các bản cập nhật sau khi sản phẩm đã ổn định trên thị trường.
- **Hỗ trợ Đa ngôn ngữ (i18n):**
  - Toàn bộ chuỗi hiển thị hiện là tiếng Anh thô (raw string), chưa bọc hàm `__()` hay `esc_html__()`.
  - *Lời khuyên:* Đây không phải lỗi bảo mật và không bị từ chối tự động. Hãy nộp bản hiện tại; nếu reviewer yêu cầu thì bọc gettext có trọng tâm, nếu không thì triển khai chuẩn `wp-i18n` ở bản v0.1.1.

---

## 6. ĐỊNH VỊ SẢN PHẨM & THÔNG ĐIỆP BÁN HÀNG (POSITIONING & MARKETING)

### 6.1. Tránh bẫy "Nói quá" (No Overclaiming)
- Không dùng các cụm từ tuyệt đối như *"Plugin duy nhất trên đời có Undo"* hay *"Động cơ an toàn nhất hệ sinh thái"* (vì các đối thủ như Saletide, Samatoras cũng có các cơ chế an toàn ở mức độ khác nhau).
- Không dùng các thuật ngữ gây kỳ vọng sai lệch như *"1-click undo"* (vì Undo của WriteLeash là tiến trình chạy nền có kiểm tra xung đột).

### 6.2. Thông điệp cốt lõi khác biệt & Đáng tin cậy (Differentiated & Truthful Copy)
> **"Bulk price changes without blindly overwriting newer edits."**
> 
> *WriteLeash giúp chủ shop WooCommerce thay đổi giá hàng loạt một cách an tâm tuyệt đối:*
> - **Xem trước từng con số:** Biết chính xác giá cũ và giá mới trước khi quyết định áp dụng.
> - **Bảo vệ dữ liệu mới hơn:** Nếu trong lúc bạn đang xem trước mà có người khác hoặc hệ thống ERP vừa cập nhật giá mới trên WooCommerce, WriteLeash sẽ tự động giữ nguyên giá mới đó, không bao giờ ghi đè làm mất dữ liệu.
> - **Chạy nền an toàn:** Tự động khôi phục và tiếp tục (Resume) nếu server bị gián đoạn.
> - **Hoàn tác có điều kiện (Safe Undo):** Khôi phục lại giá cũ an toàn mà không làm xáo trộn các sản phẩm đã được chỉnh sửa hợp lệ sau đó.

---

## 7. CHECKLIST PHÁT HÀNH & LỘ TRÌNH PHIÊN BẢN TIẾP THEO (ROADMAP)

### Giai đoạn 1: Nộp phát hành v0.1.0 (Hiện tại)
- [x] Merge PR #202 (Cải tiến UX, Logo, Dynamic Labels).
- [x] Merge PR #203 (Chuẩn hóa Nonce, Cập nhật Builder #194, Đóng gói candidate ZIP).
- [x] Chạy kiểm thử CI `pr-fast.sh` PASS 100%.
- [x] Kiểm tra Plugin Check 2.1.0 & Readme Validator thành công.
- [ ] **Hành động của Founder:** Truy cập [WordPress.org Plugin Add](https://wordpress.org/plugins/developers/add/), tải file `writeleash-0.1.0.zip` lên và gửi xét duyệt.

### Giai đoạn 2: Bản cập nhật v0.1.1 (Sau khi được duyệt)
- Xử lý các phản hồi đầu tiên từ người dùng thật.
- Bọc Gettext i18n (`esc_html__()`, `wp-i18n`) cho toàn bộ chuỗi hiển thị để mở rộng dịch thuật trên `translate.wordpress.org`.

### Giai đoạn 3: Bản nâng cấp tính năng v0.2.0
- **Live Progress Bar:** Bổ sung cơ chế AJAX Polling tự động nhảy số % tiến trình nền mà không cần bấm F5.
- **Charm Pricing:** Bổ sung tùy chọn làm tròn giá đuôi `.99`, `.95` hoặc số nguyên gần nhất.
- **Subcategory Support:** Thêm tùy chọn checkbox *"Bao gồm toàn bộ danh mục con"*.
- **Quick Edit Link:** Thêm link bấm trực tiếp vào trang sửa sản phẩm đối với các dòng bị báo lỗi định dạng giá.

---
*Báo cáo được tổng hợp tự động và đối chiếu trực tiếp với mã nguồn Git commit `c48e307`.*
