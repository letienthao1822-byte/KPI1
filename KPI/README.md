# KPI Portal

Ứng dụng PHP 8+ theo mô hình MVC, PDO và MySQL/InnoDB. Giao diện demo HTML/JS cũ vẫn được giữ ở `index.html` và truy cập tại `/KPI/index.html`.

## Chạy trên XAMPP

1. Khởi động Apache và MySQL.
2. Import `database/schema.sql` bằng phpMyAdmin.
3. Import `database/seeds/roles.sql` để tạo các vai trò và quyền ban đầu.
4. Sao chép `.env.example` thành `.env`, rồi điền thông tin MySQL cục bộ.
5. Tạo tài khoản người dùng có password hash bằng `password_hash()` và gán vai trò trong `user_roles`.
6. Mở `http://localhost/KPI/` để vào ứng dụng; bản giao diện demo cũ ở `http://localhost/KPI/index.html`.

Để nạp lại dữ liệu demo, chạy `C:\xampp\php\php.exe database\seeds\DatabaseSeeder.php` từ thư mục dự án. Seeder xóa các bản ghi demo trong `departments`, `users`, `user_roles`, `kpi_criteria`, `kpi_evaluations` và `kpi_details`, sau đó tạo lại tài khoản mẫu (mật khẩu `123456`). Không chạy trên cơ sở dữ liệu chứa dữ liệu thật.

Ví dụ tạo password hash từ PHP CLI (thay mật khẩu mẫu trước khi chạy):

```powershell
php -r "echo password_hash('ThayBangMatKhauManh', PASSWORD_DEFAULT), PHP_EOL;"
```

Không commit file `.env` hoặc password hash thật. Không có tài khoản mặc định được tạo.

## Cấu trúc

```text
app/
├── Controllers/       # Điều phối request và luồng nghiệp vụ
├── Core/              # Database, Request, Router, View, CSRF
├── Models/            # Truy vấn dữ liệu
└── Views/             # Giao diện HTML/PHP
config/                # Cấu hình ứng dụng (nếu cần mở rộng)
database/              # DDL và dữ liệu khởi tạo
public/                # Tài nguyên tĩnh khi chuyển sang public document root
routes/                # Khai báo các URL
storage/               # Log và file minh chứng
index.php              # Front controller hiện dùng cho XAMPP /KPI
```

## Các route hiện có

- `GET /KPI/` — chuyển tới đăng nhập hoặc dashboard.
- `GET|POST /KPI/login` — biểu mẫu và xử lý đăng nhập.
- `POST /KPI/api/auth/login`, `GET /KPI/api/auth/check` — đăng nhập JSON và xác thực phiên cho giao diện frontend; trang đăng nhập tĩnh ở `public/login.html`.
- `GET /KPI/admin-dashboard.html`, `/KPI/manager-dashboard.html`, `/KPI/employee-kpi.html` — đích dashboard được bảo vệ theo role.
- `GET /KPI/dashboard` — trang tổng quan cần đăng nhập.
- `POST /KPI/logout` — đăng xuất có kiểm tra CSRF.
- `GET /KPI/evaluations/self` — tải form tự đánh giá từ bộ tiêu chí đang hoạt động.
- `POST /KPI/evaluations/self` — gửi điểm và file minh chứng.
- `POST /KPI/api/evaluate/submit`, `GET /KPI/api/evaluate/pending`, `PUT /KPI/api/evaluate/approve` — API aliases khai báo trong `routes/api.php`.
- `GET /KPI/api/reports/kpi.csv` — route tương thích để Admin xuất CSV các phiếu đã được Manager duyệt.
- `POST /KPI/api/admin/criteria` — Admin thêm tiêu chí bằng JSON `name`, `weight`, `target_type` (có thể gửi `_token` CSRF); tổng trọng số tiêu chí đang hoạt động không vượt 100%.
- `POST /KPI/api/admin/period` — Admin mở kỳ theo JSON `month`, `year` (có thể gửi `_token` CSRF); tạo phiếu nháp và chi tiết tiêu chí cho toàn bộ cán bộ đang hoạt động, trừ Admin.
- `GET /KPI/api/report/export` — Admin tải CSV báo cáo điểm và xếp loại KPI.
- `GET /KPI/public/admin-dashboard.html` — giao diện Admin cấu hình tiêu chí, mở kỳ đánh giá và xuất báo cáo.
- `GET /KPI/evaluations/pending` — danh sách phiếu chờ duyệt trong phòng ban của Manager.
- `PUT|PATCH /KPI/evaluations/approve` — duyệt phiếu; JSON gồm `evaluation_id`, `scores` (`criteria_id`, `manager_score`) và `_token` CSRF.

Với cơ sở dữ liệu đã tạo trước khi có luồng duyệt Manager, áp dụng migration trong `database/migrations/` rồi chạy lại `database/seeds/roles.sql`.
Với cơ sở dữ liệu tạo trước trường loại đối tượng, chạy một lần `database/migrations/2026_10_19_master_data.sql`. Phiếu tự đánh giá chỉ gửi được trong kỳ đang mở và đang ở trạng thái nháp.
