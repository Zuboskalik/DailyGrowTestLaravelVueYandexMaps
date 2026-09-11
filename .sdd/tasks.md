# Implementation Task Checklist

Atomic and testable tasks divided by directory and phase.

---

## Phase 1: Setup & Migrations (./backend)

### 1.1 Laravel Project Initialization
- [ ] Initialize Laravel project in `./backend` directory
- [ ] Install Laravel Sanctum package via Composer
- [ ] Configure Sanctum in `config/sanctum.php`
- [ ] Add Sanctum middleware to `config/cors.php`
- [ ] Set up database connection in `.env` (MySQL: 127.0.0.1:3306, database: DailyGrowTestLaravel, user: mysql, password: mysql)

### 1.2 Database Migrations
- [ ] Create migration file for `users` table with standard Laravel auth fields
- [ ] Create migration file for `companies` table with fields: url, yandex_id (unique), name (nullable), rating (decimal 2,1), reviews_count, ratings_count, parse_status (enum), last_parsed_at
- [ ] Create migration file for `reviews` table with fields: company_id (foreign key), external_id, author_name (nullable), rating, text (nullable), review_created_at
- [ ] Create migration file for `parsing_logs` table with fields: company_id (foreign key), status (enum), error_message (nullable), reviews_collected, started_at, completed_at
- [ ] Run all migrations: `php artisan migrate`
- [ ] Verify all tables are created in MySQL database

### 1.3 Queue System Setup
- [ ] Create migration for `jobs` table: `php artisan queue:table`
- [ ] Create migration for `failed_jobs` table: `php artisan queue:failed-table`
- [ ] Run queue migrations
- [ ] Configure queue driver in `.env` to use `database`
- [ ] Create queue configuration in `config/queue.php` with custom queue name `yandex-parsing`
- [ ] Test queue worker with test job

### 1.4 Laravel Models
- [ ] Create `User` model extending `Authenticatable`
- [ ] Create `Company` model with relationships to `reviews` and `parsingLogs`
- [ ] Create `Review` model with relationship to `company`
- [ ] Create `ParsingLog` model with relationship to `company`
- [ ] Add fillable fields to all models
- [ ] Add casts for enum fields and timestamps

---

## Phase 2: Auth API & Seeder (./backend)

### 2.1 Authentication Controllers
- [ ] Create `app/Http/Controllers/Api/AuthController.php`
- [ ] Implement `login` method with email/password validation
- [ ] Implement `logout` method to revoke Sanctum tokens
- [ ] Implement `user` method to return current authenticated user
- [ ] Add form request validation for login credentials

### 2.2 Authentication Routes
- [ ] Create API routes in `routes/api.php`
- [ ] Add POST `/api/login` route (public)
- [ ] Add POST `/api/logout` route (protected with auth:sanctum)
- [ ] Add GET `/api/user` route (protected with auth:sanctum)
- [ ] Configure Sanctum SPA stateful domains in `config/sanctum.php`

### 2.3 Seed User Creation
- [ ] Create `DatabaseSeeder` for seed user
- [ ] Add seeder to create user with email: `admin@example.com`, password: `password`
- [ ] Run seeder: `php artisan db:seed`
- [ ] Verify seed user exists in database

### 2.4 Authentication Testing
- [ ] Test login endpoint with valid credentials
- [ ] Test login endpoint with invalid credentials
- [ ] Test logout endpoint
- [ ] Test user endpoint authentication
- [ ] Verify Sanctum tokens are created and revoked correctly

---

## Phase 3: Yandex Parser Service & Job (./backend)

### 3.1 Anti-Bot Protection Classes
- [ ] Create `app/Services/UserAgentRotator.php` with pool of real browser user agents
- [ ] Create `app/Services/RequestThrottler.php` with 1-3 second delays between requests
- [ ] Create `app/Services/RetryWithBackoff.php` with exponential backoff logic
- [ ] Create custom exception `app/Exceptions/RateLimitException.php`
- [ ] Unit test UserAgentRotator returns different user agents
- [ ] Unit test RequestThrottler enforces minimum delay

### 3.2 Parser Exception
- [ ] Create `app/Exceptions/ParsingStructureChangedException.php`
- [ ] Add exception message template for structure change detection
- [ ] Add exception logging logic

### 3.3 YandexMapParserService
- [ ] Create `app/Services/YandexMapParserService.php`
- [ ] Implement `extractYandexId` method with regex for yandex.ru/maps URLs
- [ ] Implement `fetchOrganizationData` method using Yandex AJAX API
- [ ] Implement `fetchReviews` method with pagination support
- [ ] Implement `parseReview` method to normalize review data
- [ ] Implement `parseCompany` method orchestrating the parsing process
- [ ] Add structure change detection logic (counter > 0 but reviews = 0)
- [ ] Integrate UserAgentRotator, RequestThrottler, and RetryWithBackoff
- [ ] Add request headers spoofing (User-Agent, Accept, Accept-Language, Referer)

### 3.4 ParseYandexCompanyJob
- [ ] Create `app/Jobs/ParseYandexCompanyJob.php` implementing ShouldQueue
- [ ] Implement `handle` method with YandexMapParserService dependency injection
- [ ] Add company status update logic (pending → processing → completed/failed)
- [ ] Implement review storage with idempotency (update by external_id)
- [ ] Create parsing log entry for job execution
- [ ] Implement `failed` method for error handling
- [ ] Configure queue connection to `yandex-parsing`
- [ ] Set job timeout to 300 seconds
- [ ] Set job retry attempts to 3

### 3.5 Parser Testing
- [ ] Unit test `extractYandexId` with valid URLs
- [ ] Unit test `extractYandexId` with invalid URLs
- [ ] Integration test `parseCompany` with real Yandex URL
- [ ] Test structure change exception triggering
- [ ] Test job execution via queue worker
- [ ] Verify idempotency (no duplicate reviews)

---

## Phase 4: Company & Review API endpoints (./backend)

### 4.1 CompanyController
- [ ] Create `app/Http/Controllers/Api/CompanyController.php`
- [ ] Implement `index` method to return all companies
- [ ] Implement `store` method with URL validation (regex for yandex.ru/maps)
- [ ] Implement `show` method to return single company with metrics
- [ ] Implement `destroy` method to delete company with cascade
- [ ] Implement `parse` method to dispatch ParseYandexCompanyJob
- [ ] Implement `parsingStatus` method to return latest parsing log
- [ ] Add validation for company creation (URL format, uniqueness of yandex_id)
- [ ] Add check for concurrent parsing requests

### 4.2 ReviewController
- [ ] Create `app/Http/Controllers/Api/ReviewController.php`
- [ ] Implement `index` method with server-side pagination (50 per page)
- [ ] Implement `show` method to return single review
- [ ] Add eager loading for company relationship
- [ ] Add ordering by `review_created_at` desc
- [ ] Add pagination metadata in response

### 4.3 API Routes Configuration
- [ ] Add company CRUD routes in `routes/api.php` (protected with auth:sanctum)
- [ ] Add custom parsing routes (POST `/api/companies/{id}/parse`, GET `/api/companies/{id}/parsing-status`)
- [ ] Add review routes (GET `/api/companies/{id}/reviews`, GET `/api/companies/{id}/reviews/{review_id}`)
- [ ] Add route model binding for companies and reviews
- [ ] Add middleware group for protected routes

### 4.4 API Testing
- [ ] Test company creation with valid URL
- [ ] Test company creation with invalid URL
- [ ] Test company listing
- [ ] Test company details retrieval
- [ ] Test company deletion
- [ ] Test parsing job dispatch
- [ ] Test parsing status retrieval
- [ ] Test reviews listing with pagination
- [ ] Test single review retrieval
- [ ] Test authentication required for all protected routes

---

## Phase 5: Frontend Auth & Router (./frontend)

### 5.1 Vue 3 Project Setup
- [ ] Initialize Vue 3 project in `./frontend` directory with Vite
- [ ] Install dependencies: Vue Router, Pinia, Axios
- [ ] Configure `vite.config.js` with path aliases (@ → src)
- [ ] Configure Vite proxy for `/api` to `http://localhost:8000`
- [ ] Create `.env` file with `VITE_API_URL=http://localhost:8000/api`

### 5.2 Axios Configuration
- [ ] Create `src/services/api.js` with Axios instance
- [ ] Configure `withCredentials: true` for Sanctum cookies
- [ ] Add request interceptor for token handling
- [ ] Add response interceptor for 401 redirect to login
- [ ] Add default headers (Accept, Content-Type)

### 5.3 Pinia Stores
- [ ] Create `src/stores/auth.js` with useAuthStore
- [ ] Implement `login` action calling `/api/login`
- [ ] Implement `logout` action calling `/api/logout`
- [ ] Implement `fetchUser` action calling `/api/user`
- [ ] Add state for user and isAuthenticated
- [ ] Create `src/stores/company.js` with useCompanyStore
- [ ] Implement `fetchCompanies` action
- [ ] Implement `createCompany` action
- [ ] Implement `fetchCompany` action
- [ ] Implement `fetchReviews` action with pagination
- [ ] Implement `triggerParsing` action
- [ ] Implement `fetchParsingStatus` action

### 5.4 Vue Router Configuration
- [ ] Create `src/router/index.js` with Vue Router setup
- [ ] Define routes: /login, /dashboard, /organizations, /organizations/:id, /organizations/:id/parse
- [ ] Add route guards for authentication (requiresAuth meta)
- [ ] Configure navigation guards for redirect logic
- [ ] Add lazy loading for route components

### 5.5 Authentication Components
- [ ] Create `src/components/LoginForm.vue` with email/password form
- [ ] Add form validation for email and password
- [ ] Add loading state for login button
- [ ] Add error display for failed login
- [ ] Create `src/views/LoginView.vue` using LoginForm component
- [ ] Create `src/views/DashboardView.vue` with basic layout
- [ ] Add logout button in DashboardView

### 5.6 App Entry Point
- [ ] Create `src/main.js` with Vue app initialization
- [ ] Register Pinia, Router, and Axios
- [ ] Create `src/App.vue` with router-view
- [ ] Add basic CSS for layout

### 5.7 Frontend Auth Testing
- [ ] Test login form with valid credentials
- [ ] Test login form with invalid credentials
- [ ] Test redirect to login on 401
- [ ] Test logout functionality
- [ ] Test route guards for protected routes
- [ ] Test Pinia store state persistence

---

## Phase 6: Frontend Settings & Parser View (./frontend)

### 6.1 Company Components
- [ ] Create `src/components/CompanyInput.vue` with URL input field
- [ ] Add regex validation for yandex.ru/maps URLs
- [ ] Add real-time validation feedback
- [ ] Add loading state for submit button
- [ ] Emit `company-added` event on success
- [ ] Create `src/components/CompanyMetrics.vue` with rating display
- [ ] Add star rating visualization
- [ ] Add reviews count display
- [ ] Add ratings count display
- [ ] Add last parsed timestamp

### 6.2 Parsing Components
- [ ] Create `src/components/ParsingStatusBadge.vue` with status prop
- [ ] Add color-coded badges (yellow=pending, blue=processing, green=completed, red=failed)
- [ ] Add status text display
- [ ] Add optional refresh button for in-progress jobs
- [ ] Create `src/components/ReviewsList.vue` with reviews array prop
- [ ] Add review card layout for each review
- [ ] Add author name and avatar placeholder
- [ ] Add rating stars display
- [ ] Add review text display
- [ ] Add review date formatting
- [ ] Add empty state illustration
- [ ] Add loading state prop

### 6.3 Pagination Component
- [ ] Create `src/components/Pagination.vue` with pagination props
- [ ] Add Previous/Next buttons with disabled states
- [ ] Add page number buttons
- [ ] Add total count display
- [ ] Emit `page-changed` event on navigation

### 6.4 Company Views
- [ ] Create `src/views/CompaniesListView.vue` with company list
- [ ] Integrate CompanyInput component
- [ ] Display company list with CompanyMetrics
- [ ] Add ParsingStatusBadge for each company
- [ ] Add delete button for each company
- [ ] Create `src/views/CompanyDetailView.vue` with company details
- [ ] Display CompanyMetrics
- [ ] Add "Start Parsing" button
- [ ] Integrate ReviewsList component
- [ ] Integrate Pagination component
- [ ] Add route parameter handling for company ID

### 6.5 Parsing Status View
- [ ] Create `src/views/ParsingStatusView.vue` with real-time status
- [ ] Display parsing status with ParsingStatusBadge
- [ ] Add reviews collected count
- [ ] Add error message display (if failed)
- [ ] Add auto-refresh for in-progress jobs (polling every 5 seconds)
- [ ] Add "Back to Company" button
- [ ] Add "Retry Parsing" button for failed jobs

### 6.6 Utilities
- [ ] Create `src/utils/validators.js` with URL validation helper
- [ ] Add regex pattern for yandex.ru/maps URLs
- [ ] Add email validation helper
- [ ] Create `src/utils/formatters.js` with date formatting
- [ ] Add relative time formatting (e.g., "2 hours ago")

### 6.7 Frontend Integration Testing
- [ ] Test company creation flow
- [ ] Test company list display
- [ ] Test company deletion
- [ ] Test parsing trigger
- [ ] Test parsing status polling
- [ ] Test reviews list pagination
- [ ] Test error handling for failed parsing
- [ ] Test URL validation feedback

---

## Phase 7: Final README.md & Deployment Guide

### 7.1 Backend README
- [ ] Create `./backend/README.md` with setup instructions
- [ ] Add PHP version requirements (8.1+)
- [ ] Add Composer installation steps
- [ ] Add environment configuration steps
- [ ] Add migration commands
- [ ] Add queue worker start command
- [ ] Add troubleshooting section

### 7.2 Frontend README
- [ ] Create `./frontend/README.md` with setup instructions
- [ ] Add Node.js version requirements (18+)
- [ ] Add npm installation steps
- [ ] Add development server start command
- [ ] Add build command for production
- [ ] Add environment variables documentation

### 7.3 Root README.md
- [ ] Create root `README.md` with project overview
- [ ] Add system requirements (PHP 8.1+, Node.js 18+, MySQL 8.0+)
- [ ] Add project structure description
- [ ] Add installation steps for both backend and frontend
- [ ] Add usage instructions
- [ ] Add API documentation reference to `.sdd/spec.md`
- [ ] Add architecture reference to `.sdd/plan.md`
- [ ] Add task checklist reference to `.sdd/tasks.md`

### 7.4 Deployment Guide (No Docker)
- [ ] Document backend deployment: `php artisan serve --host=0.0.0.0 --port=8000`
- [ ] Document frontend deployment: `npm run dev` (development) or `npm run build` (production)
- [ ] Add database migration steps for production
- [ ] Add queue worker startup instructions for production
- [ ] Add environment variable configuration for production
- [ ] Add security considerations (change default passwords, configure CORS)
- [ ] Add troubleshooting common issues

### 7.5 Final Verification
- [ ] Test complete user flow: login → add company → parse → view reviews
- [ ] Verify all API endpoints are working
- [ ] Verify frontend-backend integration
- [ ] Test error handling scenarios
- [ ] Verify queue worker processes jobs correctly
- [ ] Test pagination functionality
- [ ] Verify idempotency (no duplicate reviews)
- [ ] Test structure change detection
- [ ] Performance test with multiple companies
- [ ] Security audit (SQL injection, XSS, CSRF)

---

## Task Status Summary

### Backend Tasks (Phases 1-4)
- Total tasks: 45
- Completed: 0
- In Progress: 0
- Pending: 45

### Frontend Tasks (Phases 5-6)
- Total tasks: 35
- Completed: 0
- In Progress: 0
- Pending: 35

### Documentation Tasks (Phase 7)
- Total tasks: 10
- Completed: 0
- In Progress: 0
- Pending: 10

### Overall Progress
- Total tasks: 90
- Completed: 0 (0%)
- In Progress: 0 (0%)
- Pending: 90 (100%)

---

## Notes

- Each task is atomic and can be verified independently
- Tasks are ordered by dependency (complete previous tasks before starting next)
- All backend tasks are in `./backend` directory
- All frontend tasks are in `./frontend` directory
- Deployment uses standard Laravel and Vite commands (no Docker)
- Queue worker must be running for parsing functionality
- Database connection uses MySQL at 127.0.0.1:3306
