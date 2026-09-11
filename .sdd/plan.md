# Technical Design & Architecture

## Overview

This document outlines the technical architecture and implementation plan for the Yandex.Maps review collection system based on the functional specification in `spec.md`.

**Project Structure:**
```
DailyGrowTestLaravelVueYandexMaps/
├── backend/          # Laravel application
├── frontend/         # Vue 3 application
└── .sdd/            # Documentation
    ├── spec.md      # Functional specification
    └── plan.md      # This file
```

---

## Backend Architecture (./backend)

### Database Schema

#### Migration Files Structure
```
database/migrations/
├── 2024_XX_XX_000000_create_users_table.php
├── 2024_XX_XX_000001_create_companies_table.php
├── 2024_XX_XX_000002_create_reviews_table.php
└── 2024_XX_XX_000003_create_parsing_logs_table.php
```

#### Table: `users`
```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->timestamp('email_verified_at')->nullable();
    $table->rememberToken();
    $table->timestamps();
});
```

**Fields:**
- `id` - Primary key
- `name` - User name
- `email` - User email (unique)
- `password` - Hashed password
- `email_verified_at` - Email verification timestamp
- `remember_token` - "Remember me" token
- `created_at`, `updated_at` - Timestamps

**Indexes:**
- Unique index on `email`

---

#### Table: `companies`
```php
Schema::create('companies', function (Blueprint $table) {
    $table->id();
    $table->string('url');
    $table->string('yandex_id')->unique();
    $table->string('name')->nullable();
    $table->decimal('rating', 2, 1)->nullable();
    $table->integer('reviews_count')->default(0);
    $table->integer('ratings_count')->default(0);
    $table->enum('parse_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
    $table->timestamp('last_parsed_at')->nullable();
    $table->timestamps();
});
```

**Fields:**
- `id` - Primary key
- `url` - Yandex.Maps organization URL
- `yandex_id` - Yandex.Maps organization ID (unique)
- `name` - Organization name (optional)
- `rating` - Overall rating (1.0-5.0, nullable)
- `reviews_count` - Total number of reviews
- `ratings_count` - Total number of ratings
- `parse_status` - Current parsing status (enum)
- `last_parsed_at` - Last successful parse timestamp
- `created_at`, `updated_at` - Timestamps

**Indexes:**
- Unique index on `yandex_id`
- Index on `parse_status`
- Index on `last_parsed_at`

---

#### Table: `reviews`
```php
Schema::create('reviews', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->string('external_id');
    $table->string('author_name')->nullable();
    $table->integer('rating');
    $table->text('text')->nullable();
    $table->timestamp('review_created_at')->nullable();
    $table->timestamps();

    $table->unique(['company_id', 'external_id']);
});
```

**Fields:**
- `id` - Primary key
- `company_id` - Foreign key to companies (cascade delete)
- `external_id` - Yandex.Maps review ID
- `author_name` - Review author name (nullable)
- `rating` - Review rating (1-5)
- `text` - Review text (nullable)
- `review_created_at` - Original review date from Yandex
- `created_at`, `updated_at` - Timestamps

**Indexes:**
- Foreign key on `company_id`
- Unique composite index on (`company_id`, `external_id`)
- Index on `review_created_at`

---

#### Table: `parsing_logs`
```php
Schema::create('parsing_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->enum('status', ['pending', 'processing', 'completed', 'failed']);
    $table->text('error_message')->nullable();
    $table->integer('reviews_collected')->default(0);
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
});
```

**Fields:**
- `id` - Primary key
- `company_id` - Foreign key to companies (cascade delete)
- `status` - Job status (enum)
- `error_message` - Error details (nullable)
- `reviews_collected` - Number of reviews collected in this job
- `started_at` - Job start timestamp
- `completed_at` - Job completion timestamp
- `created_at`, `updated_at` - Timestamps

**Indexes:**
- Foreign key on `company_id`
- Index on `status`
- Index on `started_at`

---

### Parsing Strategy

#### Approach Selection: Hidden HTTP JSON Requests (AJAX/GraphQL)

**Recommended Approach:** **Hidden HTTP JSON Requests via AJAX API**

**Justification:**

| Criterion | AJAX/GraphQL | Headless Browser (Puppeteer/Playwright) |
|-----------|--------------|-----------------------------------------|
| **Performance** | ✅ Fast (100-500ms per request) | ❌ Slow (2-5s per page load) |
| **Resource Usage** | ✅ Low (HTTP client only) | ❌ High (full browser instance) |
| **Reliability** | ✅ Stable (API contracts) | ⚠️ Fragile (DOM changes) |
| **Detection Risk** | ⚠️ Medium (needs headers) | ❌ High (browser fingerprinting) |
| **Maintenance** | ✅ Easy (JSON parsing) | ❌ Hard (CSS selectors) |
| **Scalability** | ✅ High (concurrent requests) | ❌ Low (memory intensive) |
| **Implementation** | ✅ Simple (HTTP client) | ❌ Complex (browser automation) |

**Decision:** Use AJAX API approach for performance, reliability, and scalability. Fall back to headless browser only if AJAX is blocked or unavailable.

---

#### Yandex.Maps AJAX API Analysis

**Target Endpoints:**
1. **Organization Info API**
   - Endpoint: `https://yandex.ru/maps/api/business/1.x/`
   - Method: GET
   - Params: `id={yandex_id}`, `lang=ru_RU`

2. **Reviews API**
   - Endpoint: `https://yandex.ru/maps/api/reviews/1.x/`
   - Method: GET
   - Params: `bizId={yandex_id}`, `lang=ru_RU`, `page={page}`
   - Pagination: Typically 20-30 reviews per page

**Request Headers Required:**
```http
User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36
Accept: application/json, text/plain, */*
Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7
Referer: https://yandex.ru/maps/
Origin: https://yandex.ru
```

---

#### Anti-Bot Protection Strategy

**1. User-Agent Rotation**
```php
class UserAgentRotator
{
    private $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
    ];

    public function getRandom(): string
    {
        return $this->userAgents[array_rand($this->userAgents)];
    }
}
```

**2. Request Throttling & Delays**
```php
class RequestThrottler
{
    private const MIN_DELAY_MS = 1000;  // 1 second between requests
    private const MAX_DELAY_MS = 3000;  // 3 seconds between requests
    private $lastRequestTime = 0;

    public function throttle(): void
    {
        $now = microtime(true);
        $elapsed = ($now - $this->lastRequestTime) * 1000;

        if ($elapsed < self::MIN_DELAY_MS) {
            $delay = self::MIN_DELAY_MS - $elapsed + rand(0, self::MAX_DELAY_MS - self::MIN_DELAY_MS);
            usleep($delay * 1000);
        }

        $this->lastRequestTime = microtime(true);
    }
}
```

**3. Exponential Backoff on Rate Limits**
```php
class RetryWithBackoff
{
    public function execute(callable $request, int $maxRetries = 3): mixed
    {
        $attempt = 0;
        $delay = 1000; // Start with 1 second

        while ($attempt < $maxRetries) {
            try {
                return $request();
            } catch (RateLimitException $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                usleep($delay * 1000);
                $delay *= 2; // Exponential backoff
            }
        }
    }
}
```

**4. Session Cookie Management**
- Store and reuse session cookies from Yandex
- Maintain session across multiple requests
- Rotate sessions periodically

**5. IP Rotation (Optional for Production)**
- Use proxy rotation service if available
- Implement IP-based rate limiting per proxy

---

### Services & Jobs

#### Directory Structure
```
app/
├── Services/
│   └── YandexMapParserService.php
├── Jobs/
│   └── ParseYandexCompanyJob.php
├── Http/
│   └── Controllers/
│       └── Api/
│           ├── AuthController.php
│           ├── CompanyController.php
│           └── ReviewController.php
├── Models/
│   ├── User.php
│   ├── Company.php
│   ├── Review.php
│   └── ParsingLog.php
└── Exceptions/
    └── ParsingStructureChangedException.php
```

---

#### YandexMapParserService.php

**Responsibilities:**
- Extract Yandex ID from URL
- Fetch organization data from Yandex API
- Fetch reviews with pagination
- Parse and normalize review data
- Handle API errors and rate limits

**Key Methods:**
```php
class YandexMapParserService
{
    public function extractYandexId(string $url): string
    public function fetchOrganizationData(string $yandexId): array
    public function fetchReviews(string $yandexId, int $page = 1): array
    public function parseReview(array $rawReview): array
    public function parseCompany(string $url): ParseResult
}
```

**Dependencies:**
- `Illuminate\Http\Client\Factory` (HTTP client)
- `UserAgentRotator`
- `RequestThrottler`
- `RetryWithBackoff`

---

#### ParseYandexCompanyJob.php

**Responsibilities:**
- Execute parsing asynchronously via Laravel Queue
- Update company status during processing
- Store parsing results in database
- Handle errors and log failures
- Update parsing logs

**Key Methods:**
```php
class ParseYandexCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Company $company)
    public function handle(YandexMapParserService $parser): void
    public function failed(Throwable $exception): void
}
```

**Queue Configuration:**
- Queue connection: `database` or `redis`
- Queue name: `yandex-parsing`
- Timeout: 300 seconds (5 minutes)
- Tries: 3 with exponential backoff

---

#### CompanyController.php

**Responsibilities:**
- CRUD operations for companies
- Trigger parsing jobs
- Return company details with metrics

**Endpoints:**
```php
class CompanyController extends Controller
{
    public function index(): JsonResponse  // GET /api/companies
    public function store(Request $request): JsonResponse  // POST /api/companies
    public function show(Company $company): JsonResponse  // GET /api/companies/{id}
    public function destroy(Company $company): JsonResponse  // DELETE /api/companies/{id}
    public function parse(Company $company): JsonResponse  // POST /api/companies/{id}/parse
    public function parsingStatus(Company $company): JsonResponse  // GET /api/companies/{id}/parsing-status
}
```

**Validation Rules:**
```php
public function store(Request $request)
{
    $validated = $request->validate([
        'url' => 'required|url|regex:/^https:\/\/yandex\.ru\/maps\/org\/.+/i',
    ]);
    // ...
}
```

---

#### ReviewController.php

**Responsibilities:**
- Serve paginated reviews list
- Return individual review details

**Endpoints:**
```php
class ReviewController extends Controller
{
    public function index(Request $request, Company $company): JsonResponse  // GET /api/companies/{id}/reviews
    public function show(Company $company, Review $review): JsonResponse  // GET /api/companies/{id}/reviews/{review_id}
}
```

**Pagination:**
```php
public function index(Request $request, Company $company)
{
    $reviews = $company->reviews()
        ->orderBy('review_created_at', 'desc')
        ->paginate(50);

    return response()->json($reviews);
}
```

---

#### AuthController.php

**Responsibilities:**
- Handle SPA authentication via Sanctum
- Login/logout operations
- Return current user info

**Endpoints:**
```php
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse  // POST /api/login
    public function logout(Request $request): JsonResponse  // POST /api/logout
    public function user(Request $request): JsonResponse  // GET /api/user
}
```

---

### API Routes

#### routes/api.php
```php
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

// Sanctum SPA Authentication
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/user', [AuthController::class, 'user'])->middleware('auth:sanctum');

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    // Companies
    Route::apiResource('companies', CompanyController::class);
    Route::post('/companies/{company}/parse', [CompanyController::class, 'parse']);
    Route::get('/companies/{company}/parsing-status', [CompanyController::class, 'parsingStatus']);

    // Reviews
    Route::get('/companies/{company}/reviews', [ReviewController::class, 'index']);
    Route::get('/companies/{company}/reviews/{review}', [ReviewController::class, 'show']);
});
```

---

### Queue Configuration

#### config/queue.php
```php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ],
],

'failed' => [
    'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => env('DB_CONNECTION', 'mysql'),
    'table' => 'failed_jobs',
],
```

#### Queue Worker Command
```bash
php artisan queue:work --queue=yandex-parsing --tries=3 --timeout=300
```

---

## Frontend Architecture (./frontend)

### Directory Structure
```
src/
├── main.js                 # Application entry point
├── App.vue                 # Root component
├── router/
│   └── index.js           # Vue Router configuration
├── stores/
│   ├── auth.js            # useAuthStore
│   └── company.js         # useCompanyStore
├── components/
│   ├── LoginForm.vue
│   ├── CompanyInput.vue
│   ├── ParsingStatusBadge.vue
│   ├── CompanyMetrics.vue
│   ├── ReviewsList.vue
│   └── Pagination.vue
├── views/
│   ├── LoginView.vue
│   ├── DashboardView.vue
│   ├── CompaniesListView.vue
│   ├── CompanyDetailView.vue
│   └── ParsingStatusView.vue
├── services/
│   └── api.js             # Axios configuration
└── utils/
    └── validators.js      # URL validation helpers
```

---

### Vue 3 + Vite Configuration

#### vite.config.js
```javascript
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
```

---

### Axios Configuration

#### src/services/api.js
```javascript
import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
  withCredentials: true, // Required for Sanctum SPA cookies
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})

// Request interceptor
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  },
  (error) => Promise.reject(error)
)

// Response interceptor
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Redirect to login on unauthorized
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export default api
```

---

### Pinia Stores

#### src/stores/auth.js
```javascript
import { defineStore } from 'pinia'
import api from '@/services/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false,
  }),

  actions: {
    async login(credentials) {
      const response = await api.post('/login', credentials)
      this.user = response.data.user
      this.isAuthenticated = true
    },

    async logout() {
      await api.post('/logout')
      this.user = null
      this.isAuthenticated = false
    },

    async fetchUser() {
      const response = await api.get('/user')
      this.user = response.data
      this.isAuthenticated = true
    },
  },
})
```

---

#### src/stores/company.js
```javascript
import { defineStore } from 'pinia'
import api from '@/services/api'

export const useCompanyStore = defineStore('company', {
  state: () => ({
    companies: [],
    currentCompany: null,
    reviews: [],
    pagination: {
      current_page: 1,
      last_page: 1,
      per_page: 50,
      total: 0,
    },
    parsingStatus: null,
  }),

  actions: {
    async fetchCompanies() {
      const response = await api.get('/companies')
      this.companies = response.data.data
    },

    async createCompany(url) {
      const response = await api.post('/companies', { url })
      this.companies.push(response.data)
      return response.data
    },

    async fetchCompany(id) {
      const response = await api.get(`/companies/${id}`)
      this.currentCompany = response.data
      return response.data
    },

    async fetchReviews(companyId, page = 1) {
      const response = await api.get(`/companies/${companyId}/reviews`, {
        params: { page },
      })
      this.reviews = response.data.data
      this.pagination = {
        current_page: response.data.current_page,
        last_page: response.data.last_page,
        per_page: response.data.per_page,
        total: response.data.total,
      }
    },

    async triggerParsing(companyId) {
      const response = await api.post(`/companies/${companyId}/parse`)
      return response.data
    },

    async fetchParsingStatus(companyId) {
      const response = await api.get(`/companies/${companyId}/parsing-status`)
      this.parsingStatus = response.data
      return response.data
    },
  },
})
```

---

### Components

#### src/components/LoginForm.vue
**Purpose:** User authentication form

**Props:** None

**Emits:** None

**Key Features:**
- Email/password input fields
- Form validation
- Error display
- Login button with loading state

---

#### src/components/CompanyInput.vue
**Purpose:** Input form for adding Yandex.Maps organization URL

**Props:** None

**Emits:** `company-added` (when company is successfully added)

**Key Features:**
- URL input field
- Regex validation for yandex.ru/maps URLs
- Real-time validation feedback
- Submit button with loading state

---

#### src/components/ParsingStatusBadge.vue
**Purpose:** Display parsing status with visual indicator

**Props:**
- `status` (String): 'pending', 'processing', 'completed', 'failed'

**Emits:** None

**Key Features:**
- Color-coded badge (yellow, blue, green, red)
- Status text
- Optional refresh button for in-progress jobs

---

#### src/components/CompanyMetrics.vue
**Purpose:** Display company metrics (rating, reviews count, ratings count)

**Props:**
- `company` (Object): Company data with metrics

**Emits:** None

**Key Features:**
- Star rating display
- Reviews count
- Ratings count
- Last parsed timestamp

---

#### src/components/ReviewsList.vue
**Purpose:** Display paginated list of reviews

**Props:**
- `reviews` (Array): Array of review objects
- `loading` (Boolean): Loading state

**Emits:** None

**Key Features:**
- Review card for each review
- Author name and avatar placeholder
- Rating stars
- Review text
- Review date
- Empty state illustration

---

#### src/components/Pagination.vue
**Purpose:** Pagination controls for reviews list

**Props:**
- `currentPage` (Number)
- `lastPage` (Number)
- `total` (Number)

**Emits:** `page-changed` (Number)

**Key Features:**
- Previous/Next buttons
- Page number buttons
- Total count display
- Disabled states for boundaries

---

### Vue Router Configuration

#### src/router/index.js
```javascript
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { requiresAuth: false },
  },
  {
    path: '/dashboard',
    name: 'dashboard',
    component: () => import('@/views/DashboardView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/organizations',
    name: 'organizations',
    component: () => import('@/views/CompaniesListView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/organizations/:id',
    name: 'organization',
    component: () => import('@/views/CompanyDetailView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/organizations/:id/parse',
    name: 'parsing-status',
    component: () => import('@/views/ParsingStatusView.vue'),
    meta: { requiresAuth: true },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()
  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next('/login')
  } else {
    next()
  }
})

export default router
```

---

## Non-Functional Requirements & Implementation Strategy

### Q1: Anti-Ban Strategy

**Problem:** Yandex may block requests that appear automated.

**Solution:**
1. **User-Agent Rotation:** Randomly select from a pool of real browser user agents
2. **Request Throttling:** Implement delays between requests (1-3 seconds)
3. **Session Management:** Maintain and reuse session cookies
4. **Exponential Backoff:** Retry failed requests with increasing delays
5. **Header Spoofing:** Include realistic headers (Accept, Accept-Language, Referer)
6. **Rate Limiting:** Monitor and respect Yandex's rate limits
7. **Fallback Strategy:** Implement headless browser (Puppeteer) as fallback if AJAX is blocked

**Implementation:**
```php
// In YandexMapParserService
private function makeRequest(string $url): Response
{
    $headers = [
        'User-Agent' => $this->userAgentRotator->getRandom(),
        'Accept' => 'application/json, text/plain, */*',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'Referer' => 'https://yandex.ru/maps/',
    ];

    return $this->throttler->throttle(function () use ($url, $headers) {
        return $this->retry->execute(function () use ($url, $headers) {
            return Http::withHeaders($headers)->get($url);
        });
    });
}
```

---

### Q2: Markup Change Detection

**Problem:** Yandex may change HTML/API structure, breaking the parser.

**Solution:**
1. **Structure Change Exception:** Throw `ParsingStructureChangedException` when:
   - Parser returns 0 reviews
   - Yandex counter shows > 0 reviews
2. **Alerting:** Log and notify developers immediately
3. **Fallback to Headless Browser:** If AJAX fails, try parsing HTML
4. **Versioned Parsers:** Maintain multiple parser versions
5. **Health Checks:** Regular test parsing of known organizations
6. **Monitoring:** Track parsing success rate

**Implementation:**
```php
class YandexMapParserService
{
    public function parseCompany(string $url): ParseResult
    {
        $yandexId = $this->extractYandexId($url);
        $counter = $this->fetchReviewCounter($yandexId);
        $reviews = $this->fetchReviews($yandexId);

        if ($counter > 0 && count($reviews) === 0) {
            throw new ParsingStructureChangedException(
                "Structure changed: Counter shows {$counter} reviews but parser returned 0"
            );
        }

        return new ParseResult($reviews, $counter);
    }
}
```

---

### Q3: Scaling to 50 Branches

**Problem:** System must handle parsing for 50+ organizations concurrently.

**Solution:**
1. **Queue System:** Use Laravel Queues for async processing
2. **Horizontal Scaling:** Multiple queue workers
3. **Database Indexing:** Optimize queries for performance
4. **Connection Pooling:** Reuse database connections
5. **Rate Limiting per Organization:** Prevent flooding Yandex
6. **Monitoring:** Track queue depth and processing time
7. **Load Balancing:** Distribute parsing across workers

**Implementation:**
```bash
# Start multiple queue workers
php artisan queue:work --queue=yandex-parsing --tries=3 --timeout=300 &
php artisan queue:work --queue=yandex-parsing --tries=3 --timeout=300 &
php artisan queue:work --queue=yandex-parsing --tries=3 --timeout=300 &
```

**Database Optimizations:**
```php
// Add composite indexes
Schema::table('reviews', function (Blueprint $table) {
    $table->index(['company_id', 'review_created_at']);
});

// Use eager loading
$companies = Company::with('reviews')->get();
```

---

### Q4: Change History Tracking

**Problem:** Need to track changes in reviews over time.

**Solution:**
1. **Review Versioning:** Store review history in separate table
2. **Snapshot Strategy:** Create snapshots before updates
3. **Change Detection:** Compare new vs old review data
4. **Audit Log:** Track all modifications
5. **Diff Display:** Show changes to users

**Additional Table: `review_history`**
```php
Schema::create('review_history', function (Blueprint $table) {
    $table->id();
    $table->foreignId('review_id')->constrained()->onDelete('cascade');
    $table->string('external_id');
    $table->string('author_name')->nullable();
    $table->integer('rating');
    $table->text('text')->nullable();
    $table->string('change_type'); // 'created', 'updated', 'deleted'
    $table->json('changed_fields')->nullable();
    $table->timestamp('review_created_at')->nullable();
    $table->timestamps();
});
```

**Implementation:**
```php
class ReviewObserver
{
    public function updated(Review $review)
    {
        $changes = $review->getDirty();
        ReviewHistory::create([
            'review_id' => $review->id,
            'external_id' => $review->external_id,
            'author_name' => $review->author_name,
            'rating' => $review->rating,
            'text' => $review->text,
            'change_type' => 'updated',
            'changed_fields' => $changes,
            'review_created_at' => $review->review_created_at,
        ]);
    }
}
```

---

## Development Phases

### Phase 1: Foundation (Week 1)
- [ ] Setup Laravel backend with Sanctum
- [ ] Setup Vue 3 frontend with Vite
- [ ] Create database migrations
- [ ] Implement basic authentication
- [ ] Setup queue system

### Phase 2: Core Features (Week 2)
- [ ] Implement Company CRUD
- [ ] Implement YandexMapParserService
- [ ] Implement ParseYandexCompanyJob
- [ ] Create CompanyInput component
- [ ] Create CompanyList view

### Phase 3: Parsing Implementation (Week 3)
- [ ] Implement AJAX API integration
- [ ] Add anti-bot protection
- [ ] Implement pagination for reviews
- [ ] Create ParsingStatusBadge component
- [ ] Create ReviewsList component

### Phase 4: Refinement (Week 4)
- [ ] Add error handling
- [ ] Implement structure change detection
- [ ] Add monitoring and logging
- [ ] Performance optimization
- [ ] Testing and bug fixes

---

## README Draft

```markdown
# Yandex.Maps Review Collector

Application for collecting reviews from Yandex.Maps using Laravel (backend) and Vue 3 (frontend).

## Requirements
- PHP 8.1+
- Composer
- Node.js 18+
- MySQL 8.0+

## Installation

### Backend
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan queue:work
```

### Frontend
```bash
cd frontend
npm install
npm run dev
```

## Usage
1. Login with seed user credentials
2. Add Yandex.Maps organization URL
3. Trigger parsing job
4. View collected reviews with pagination

## API Documentation
See `.sdd/spec.md` for detailed API endpoints.

## Architecture
See `.sdd/plan.md` for technical architecture details.
```

---

## Next Steps

1. **Create seed user migration and seeder**
2. **Implement YandexMapParserService with AJAX API**
3. **Setup Laravel Queue system**
4. **Create Vue 3 frontend structure**
5. **Implement authentication flow**
6. **Build Company CRUD operations**
7. **Implement parsing job with anti-bot protection**
8. **Create reviews list with pagination**
9. **Add error handling and monitoring**
10. **Testing and optimization**
