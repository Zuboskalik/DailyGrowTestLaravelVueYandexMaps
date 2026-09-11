# Functional Specification

## System Overview

Application for collecting reviews from Yandex.Maps with SDD methodology.

**Architecture:**
- Monorepository without Docker
- Backend: Laravel (SPA Auth via Sanctum, SQLite/MySQL, Queues)
- Frontend: Vue 3 (Composition API, Pinia, Vue Router)
- Documentation: ./.sdd/

**Test Database:**
- Server: 127.0.0.1:3306
- Database: DailyGrowTestLaravel
- Login: mysql
- Password: mysql

---

## User Stories

### 1. Seed User Login
**Priority:** High

**Description:** As a seed user, I need to log in to the application to access the review collection functionality.

**Acceptance Criteria:**
- User can authenticate via SPA Auth using Laravel Sanctum
- Session management is handled by the frontend
- User can log out
- Unauthorized users are redirected to login page

---

### 2. Add Yandex.Maps Organization Link
**Priority:** High

**Description:** As an authenticated user, I need to add a Yandex.Maps organization link to start parsing reviews.

**Acceptance Criteria:**
- User can input a Yandex.Maps organization URL
- URL is validated using regex patterns for yandex.ru/maps formats
- Supported URL formats:
  - `https://yandex.ru/maps/org/organization-name/1234567890/`
  - `https://yandex.ru/maps/123/city/?ll=xxx.xxx,yyy.yyy&z=12&pt=xxx.xxx,yyy.yyy,point`
- Invalid URLs show appropriate error messages
- Valid URLs are saved to the database

---

### 3. Asynchronous Parsing with Status Tracking
**Priority:** High

**Description:** As an authenticated user, I need to trigger async parsing of reviews and track its progress.

**Acceptance Criteria:**
- Parsing is executed via Laravel Queue Jobs
- Parsing status is displayed in real-time with one of the following states:
  - **Pending** - Job queued, waiting to start
  - **Processing** - Job is actively parsing reviews
  - **Completed** - Parsing finished successfully
  - **Failed** - Parsing encountered an error
- Status updates are reflected in the UI without page refresh
- User can re-trigger parsing for failed jobs

---

### 4. View Reviews List with Pagination
**Priority:** High

**Description:** As an authenticated user, I need to view a paginated list of collected reviews with key metrics.

**Acceptance Criteria:**
- Reviews are displayed in a list format
- Server-side pagination (50 reviews per page)
- Each review displays:
  - Rating (stars or numeric)
  - Number of ratings
  - Number of reviews
  - Review text (if available)
  - Review date
- Pagination controls allow navigation between pages
- Total count of reviews is displayed
- Empty state is shown when no reviews exist

---

## Parsing Rules & Business Logic

### 1. Review Collection Limits
- Collect up to ~600 reviews per organization
- Parsing stops when the limit is reached or no more reviews are available

### 2. Idempotency
- Reviews are updated based on `external_id` (Yandex.Maps review ID)
- Duplicate reviews are prevented:
  - If a review with the same `external_id` exists, update its content
  - Never create duplicate entries for the same external_id
- Each organization can have only one active parsing job at a time

### 3. Structure Change Detection
- **ParsingStructureChangedException** is thrown when:
  - Parser returns 0 reviews
  - Yandex.Marts counter shows > 0 reviews
  - This indicates a change in Yandex.Maps HTML structure
- Exception logs the incident for manual review
- User is notified about the structure change

---

## Edge Cases & Failure Handling

### 1. URL Validation Errors
- **Scenario:** User enters invalid Yandex.Maps URL
- **Handling:**
  - Frontend validation with regex
  - Backend validation confirms URL format
  - Clear error message: "Invalid Yandex.Maps URL format"
  - URL is not saved to database

### 2. Yandex.Maps Ban/Rate Limiting
- **Scenario:** Yandex blocks requests due to rate limiting
- **Handling:**
  - Job status changes to "Failed"
  - Error is logged with details
  - User notification: "Yandex.Marts blocked the request. Please try again later."
  - Exponential backoff for retry (if configured)

### 3. Request Timeouts
- **Scenario:** Yandex.Maps request exceeds timeout threshold
- **Handling:**
  - Job status changes to "Failed"
  - Timeout is logged
  - User notification: "Request timeout. The service may be unavailable."
  - Partial results (if any) are preserved

### 4. Empty Reviews
- **Scenario:** Parsing completes but no reviews are found
- **Handling:**
  - Check if Yandex.Maps counter shows > 0 reviews
  - If counter > 0 and parser returns 0 → Throw ParsingStructureChangedException
  - If counter = 0 and parser returns 0 → Mark as "Completed" with message "No reviews found"
  - User is informed of the outcome

### 5. Network Errors
- **Scenario:** Network connectivity issues during parsing
- **Handling:**
  - Job status changes to "Failed"
  - Error is logged with stack trace
  - User notification: "Network error occurred. Please check your connection."
  - Job can be retried manually by the user

### 6. Malformed Review Data
- **Scenario:** Review data is incomplete or corrupted
- **Handling:**
  - Skip malformed reviews and log warnings
  - Continue parsing remaining reviews
  - Partial results are saved
  - User is notified about skipped reviews

### 7. Concurrent Parsing Requests
- **Scenario:** User triggers parsing while another job is in progress
- **Handling:**
  - Reject new parsing request for the same organization
  - User notification: "Parsing is already in progress for this organization"
  - Current job status is displayed

---

## Data Models

### Organization
- `id` - Primary key
- `url` - Yandex.Marts organization URL
- `external_id` - Yandex.Marts organization ID
- `name` - Organization name (optional)
- `created_at` - Timestamp
- `updated_at` - Timestamp

### Review
- `id` - Primary key
- `organization_id` - Foreign key to Organization
- `external_id` - Yandex.Marts review ID (unique per organization)
- `rating` - Review rating (1-5)
- `text` - Review text (nullable)
- `author` - Author name (nullable)
- `date` - Review date
- `created_at` - Timestamp
- `updated_at` - Timestamp

### ParsingJob
- `id` - Primary key
- `organization_id` - Foreign key to Organization
- `status` - Job status (Pending, Processing, Completed, Failed)
- `error_message` - Error details (nullable)
- `reviews_collected` - Number of reviews collected
- `started_at` - Timestamp (nullable)
- `completed_at` - Timestamp (nullable)
- `created_at` - Timestamp
- `updated_at` - Timestamp

---

## API Endpoints

### Authentication
- `POST /api/login` - Authenticate user
- `POST /api/logout` - Logout user
- `GET /api/user` - Get current user info

### Organizations
- `GET /api/organizations` - List organizations
- `POST /api/organizations` - Create organization
- `GET /api/organizations/{id}` - Get organization details
- `DELETE /api/organizations/{id}` - Delete organization

### Parsing
- `POST /api/organizations/{id}/parse` - Trigger parsing
- `GET /api/organizations/{id}/parsing-status` - Get parsing status

### Reviews
- `GET /api/organizations/{id}/reviews` - Get paginated reviews list
- `GET /api/organizations/{id}/reviews/{review_id}` - Get specific review

---

## Frontend Routes

- `/login` - Login page
- `/dashboard` - Main dashboard
- `/organizations` - Organizations list
- `/organizations/{id}` - Organization details with reviews
- `/organizations/{id}/parse` - Parsing status page

---

## Non-Functional Requirements

### Performance
- UI response time < 200ms for navigation
- API response time < 500ms for typical requests
- Parsing job should complete within 5 minutes for 600 reviews

### Security
- All API endpoints require authentication (except login)
- SQL injection prevention via parameterized queries
- XSS prevention via input sanitization
- CSRF protection for state-changing operations

### Reliability
- 99% uptime for API endpoints
- Failed jobs are logged and recoverable
- Database transactions for critical operations

### Scalability
- Support for multiple concurrent parsing jobs
- Database indexing for efficient queries
- Queue system for async processing
