# Kral Kafe - QR Consumption & AI Stock Reconciliation System

## Implementation Plan

### Project Overview

A closed-circuit, internal-use web application for managing café consumption tracking and AI-assisted stock reconciliation.

---

## Phase 1: Project Foundation & Database Design

### 1.1 Technology Stack
- **Backend**: PHP 8+ with Laravel 10
- **Frontend**: Responsive HTML5/CSS3/JavaScript (Mobile-first)
- **Database**: MySQL 8
- **AI Integration**: OpenAI Vision API / Google Cloud Vision (for stock photo analysis)
- **Authentication**: Laravel Sanctum (API tokens for mobile access)

### 1.2 Database Schema

```sql
-- Users & Authentication
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('student', 'admin') DEFAULT 'student',
    subscription_status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    subscription_start DATE,
    subscription_end DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Locations (Shelves, Cabinets, Fridges)
CREATE TABLE locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('shelf', 'cabinet', 'fridge') NOT NULL,
    qr_code VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Products
CREATE TABLE products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(50),
    unit_price DECIMAL(10,2) NOT NULL,
    unit_type ENUM('piece', 'kg', 'liter') DEFAULT 'piece',
    image_url VARCHAR(255),
    barcode VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Product-Location Mapping (where each product is stored)
CREATE TABLE product_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    expected_quantity INT DEFAULT 0,
    min_quantity INT DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (location_id) REFERENCES locations(id),
    UNIQUE KEY (product_id, location_id)
);

-- User Consumption Ledger
CREATE TABLE consumptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    consumed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_undone BOOLEAN DEFAULT FALSE,
    undone_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (location_id) REFERENCES locations(id)
);

-- Stock Records (Admin-verified ground truth)
CREATE TABLE stock_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    location_id INT NOT NULL,
    product_id INT NOT NULL,
    record_type ENUM('opening', 'closing') NOT NULL,
    verified_quantity INT NOT NULL,
    ai_suggested_quantity INT,
    ai_confidence DECIMAL(3,2),
    admin_id INT NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (admin_id) REFERENCES users(id)
);

-- Stock Photos (for AI processing)
CREATE TABLE stock_photos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    location_id INT NOT NULL,
    stock_record_batch_id VARCHAR(36) NOT NULL, -- Groups photos from same session
    record_type ENUM('opening', 'closing') NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    ai_analysis_json JSON,
    processed_at TIMESTAMP NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    admin_id INT NOT NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (admin_id) REFERENCES users(id)
);

-- Monthly Billing Summary
CREATE TABLE monthly_bills (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    bill_month DATE NOT NULL, -- First day of month
    total_items INT DEFAULT 0,
    total_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM('pending', 'sent', 'paid') DEFAULT 'pending',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY (user_id, bill_month)
);

-- Discrepancy Logs (AI-detected anomalies)
CREATE TABLE discrepancy_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    location_id INT NOT NULL,
    product_id INT NOT NULL,
    expected_quantity INT,
    actual_quantity INT,
    difference INT,
    record_type ENUM('opening', 'closing') NOT NULL,
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved BOOLEAN DEFAULT FALSE,
    resolution_notes TEXT,
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

---

## Phase 2: User Consumption Flow

### 2.1 QR Code Scanning Interface
- Location-specific QR codes link to: `/consume/{location_qr_code}`
- Mobile-optimized, single-page consumption interface
- Large touch-friendly buttons for product selection
- Instant feedback with success animations

### 2.2 User Features
1. **Quick Consume Page**
   - Grid of available products at location
   - +/- quantity selectors
   - Confirm button with 10-second undo window
   
2. **Personal Dashboard**
   - Current month total (amount)
   - Consumption history (scrollable list)
   - Simple, clean interface

### 2.3 Authentication Flow
- Students log in once per device (remember me)
- Session persists for mobile convenience
- PIN-based quick re-auth option (optional)

---

## Phase 3: Admin Stock Reconciliation

### 3.1 Admin Dashboard
1. **Location Management**
   - Add/edit/disable locations
   - Generate/print QR codes
   - View location stock status

2. **Product Management**
   - CRUD for products
   - Category management
   - Price history

3. **Stock Capture Workflow**
   ```
   [Select Location] → [Capture Photos] → [AI Processing] → [Review & Confirm]
   ```

### 3.2 AI Stock Analysis
1. **Photo Upload Interface**
   - Multi-photo capture per location
   - Preview before submit
   - Batch processing

2. **AI Analysis Response**
   ```json
   {
     "products_detected": [
       {
         "product_id": 1,
         "name": "Cola 330ml",
         "estimated_quantity": 8,
         "confidence": 0.92,
         "bounding_box": {...}
       }
     ],
     "anomalies": [
       {
         "type": "unexpected_low_stock",
         "product_id": 1,
         "expected": 15,
         "detected": 8
       }
     ],
     "overall_confidence": 0.87
   }
   ```

3. **Admin Review Interface**
   - Side-by-side: Photo + AI suggestions
   - Editable quantity fields
   - Confirm/Override buttons
   - Discrepancy notes field

### 3.3 Reporting
1. **Monthly Billing Export**
   - Per-user consumption breakdown
   - CSV/Excel export
   - Email distribution option

2. **Stock Reports**
   - Daily opening/closing comparison
   - Discrepancy trends
   - Product movement analytics

---

## Phase 4: AI Integration

### 4.1 Computer Vision Pipeline
- **Primary**: OpenAI GPT-4 Vision API
- **Fallback**: Google Cloud Vision + Custom ML model
- **Local Testing**: Mock responses for development

### 4.2 AI Prompt Engineering
```
Analyze this image of a café {location_type}. 
Identify and count the following products:
{product_list_with_images}

Return JSON with:
- Product name
- Estimated count
- Confidence (0-1)
- Any anomalies detected

Focus on accuracy. If uncertain, flag for human review.
```

### 4.3 Privacy & KVKK Compliance
- No user images ever captured
- Stock photos stored locally (not cloud)
- Retention policy: 30 days
- No facial recognition integration

---

## Phase 5: File Structure

```
kral-kafe/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   ├── Admin/
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── LocationController.php
│   │   │   │   ├── ProductController.php
│   │   │   │   ├── StockController.php
│   │   │   │   └── ReportController.php
│   │   │   ├── User/
│   │   │   │   ├── ConsumptionController.php
│   │   │   │   └── DashboardController.php
│   │   │   └── Api/
│   │   │       ├── ConsumptionApiController.php
│   │   │       └── StockApiController.php
│   │   └── Middleware/
│   ├── Models/
│   │   ├── User.php
│   │   ├── Product.php
│   │   ├── Location.php
│   │   ├── Consumption.php
│   │   ├── StockRecord.php
│   │   └── ...
│   └── Services/
│       ├── AIStockAnalyzer.php
│       ├── BillingService.php
│       └── QRCodeService.php
├── resources/
│   ├── views/
│   │   ├── admin/
│   │   ├── user/
│   │   └── consume/ (QR landing pages)
│   └── css/
├── public/
│   ├── qrcodes/
│   └── uploads/
│       └── stock_photos/
├── database/
│   └── migrations/
└── routes/
    ├── web.php
    └── api.php
```

---

## Phase 6: Implementation Timeline

| Week | Focus | Deliverables |
|------|-------|--------------|
| 1 | Foundation | Laravel setup, DB migrations, Auth |
| 2 | User Flow | QR scanning, consumption logging, undo |
| 3 | User Dashboard | History, monthly totals, responsive UI |
| 4 | Admin Basic | Product/Location CRUD, QR generation |
| 5 | Stock Capture | Photo upload, storage, workflow |
| 6 | AI Integration | Vision API connection, analysis |
| 7 | Review Interface | Admin verification, corrections |
| 8 | Reporting | Billing exports, analytics |
| 9 | Polish | Testing, UX refinements, security audit |
| 10 | Deployment | Production setup, documentation |

---

## Next Steps

1. **Confirm technology choices** (Laravel vs. other PHP frameworks)
2. **Set up development environment** (XAMPP already available)
3. **Initialize Laravel project**
4. **Create database migrations**
5. **Begin with User Consumption Flow** (highest user-facing priority)

---

## Questions for Clarification

1. **AI Provider**: Do you have a preference for OpenAI Vision API vs Google Cloud Vision?
2. **Subscription Model**: Is subscription managed externally or within this system?
3. **Multi-language**: Turkish/English support needed?
4. **Payment Integration**: Is billing export-only, or payment gateway integration needed?
5. **Offline Mode**: Should the consumption interface work offline (with sync)?
