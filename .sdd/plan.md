# Технический архитектурный план: Яндекс.Карты Парсер Отзывов

## 1. Structure & Tech Stack

### 1.1 Backend Stack
- **Framework**: Laravel 11
- **Authentication**: Laravel Sanctum (SPA mode)
- **Queue**: Database driver (с возможностью переключения на Redis)
- **HTTP Client**: Guzzle для запросов к Яндекс API
- **Browser Automation**: Puppeteer/Playwright (Node.js service) как фоллбэк
- **Database**: MySQL 8.0+
- **Cache**: Redis (опционально для кеширования токенов)

### 1.2 Frontend Stack
- **Framework**: Vue 3 (Composition API, `<script setup>`)
- **State Management**: Pinia
- **Routing**: Vue Router 4
- **HTTP Client**: Axios (с `withCredentials: true` для Sanctum cookies)
- **UI Components**: Tailwind CSS + Headless UI (или Element Plus)
- **Build Tool**: Vite

### 1.3 DevOps & Infrastructure
- **Containerization**: Docker & Docker Compose
- **Web Server**: Nginx (frontend), PHP-FPM (backend)
- **Process Manager**: Supervisor для queue workers
- **Version Control**: Git

## 2. Database Schema (Laravel Migrations)

### 2.1 Users Table
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

**Seeder**: Admin user (admin@example.com / password)

### 2.2 Companies Table
```php
Schema::create('companies', function (Blueprint $table) {
    $table->id();
    $table->string('yandex_url');
    $table->string('yandex_org_id')->nullable(); // Извлекается из URL
    $table->string('name')->nullable();
    $table->decimal('rating', 2, 1)->nullable();
    $table->integer('reviews_count')->default(0);
    $table->integer('ratings_count')->default(0);
    $table->enum('parse_status', ['idle', 'pending', 'processing', 'completed', 'failed'])->default('idle');
    $table->timestamp('last_parsed_at')->nullable();
    $table->timestamps();
    
    $table->index('yandex_org_id');
    $table->index('parse_status');
});
```

### 2.3 Reviews Table
```php
Schema::create('reviews', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->string('external_id')->unique(); // Для дедупликации
    $table->string('author_name');
    $table->string('author_avatar')->nullable();
    $table->integer('rating'); // 1-5
    $table->text('text')->nullable();
    $table->timestamp('review_created_at');
    $table->string('raw_payload_hash')->nullable(); // Для отслеживания изменений
    $table->timestamps();
    
    $table->index(['company_id', 'external_id']);
    $table->index('review_created_at');
    $table->index('rating');
});
```

### 2.4 Parse Logs Table
```php
Schema::create('parse_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->foreignId('parse_job_id')->nullable()->constrained('jobs')->onDelete('set null');
    $table->enum('status', ['started', 'completed', 'failed']);
    $table->string('error_message')->nullable();
    $table->json('payload_snapshot')->nullable(); // Для отслеживания изменений структуры
    $table->integer('reviews_collected')->default(0);
    $table->timestamps();
    
    $table->index('company_id');
    $table->index('status');
});
```

### 2.5 Jobs Table (Laravel default)
```php
// Используем стандартную таблицу jobs из Laravel
// Дополнительные поля через payload
```

## 3. Architecture Pattern (Backend)

### 3.1 Directory Structure
```
app/
├── Http/
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── CompanyController.php
│   │   └── ReviewController.php
│   ├── Middleware/
│   │   └── Ensure sanctum authentication
│   └── Requests/
│       ├── LoginRequest.php
│       ├── StoreCompanyRequest.php
│       └── ...
├── Models/
│   ├── User.php
│   ├── Company.php
│   ├── Review.php
│   └── ParseLog.php
├── Services/
│   └── YandexParser/
│       ├── YandexMapParserService.php
│       ├── YandexAjaxParser.php
│       ├── YandexPlaywrightParser.php
│       └── Contracts/
│           └── ParserInterface.php
├── DTOs/
│   ├── CompanyDataDTO.php
│   ├── ReviewDTO.php
│   └── ParseResultDTO.php
├── Jobs/
│   └── ParseYandexCompanyJob.php
└── Repositories/
    ├── CompanyRepository.php
    └── ReviewRepository.php
```

### 3.2 Service Layer

#### YandexMapParserService
```php
namespace App\Services\YandexParser;

class YandexMapParserService
{
    public function __construct(
        private YandexAjaxParser $ajaxParser,
        private YandexPlaywrightParser $playwrightParser
    ) {}
    
    public function parse(string $yandexUrl): ParseResultDTO
    {
        // Попытка AJAX парсинга
        try {
            return $this->ajaxParser->parse($yandexUrl);
        } catch (AjaxParserException $e) {
            // Фоллбэк на Playwright
            return $this->playwrightParser->parse($yandexUrl);
        }
    }
}
```

#### ParserInterface
```php
namespace App\Services\YandexParser\Contracts;

interface ParserInterface
{
    public function parse(string $yandexUrl): ParseResultDTO;
    public function extractOrgId(string $url): string;
}
```

### 3.3 DTOs

#### CompanyDataDTO
```php
namespace App\DTOs;

class CompanyDataDTO
{
    public function __construct(
        public readonly string $yandexOrgId,
        public readonly ?string $name,
        public readonly ?float $rating,
        public readonly int $reviewsCount,
        public readonly int $ratingsCount
    ) {}
}
```

#### ReviewDTO
```php
namespace App\DTOs;

class ReviewDTO
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $authorName,
        public readonly ?string $authorAvatar,
        public readonly int $rating,
        public readonly ?string $text,
        public readonly \DateTime $reviewCreatedAt
    ) {}
}
```

#### ParseResultDTO
```php
namespace App\DTOs;

class ParseResultDTO
{
    public function __construct(
        public readonly CompanyDataDTO $companyData,
        public readonly array $reviews, // ReviewDTO[]
        public readonly string $rawPayloadHash
    ) {}
}
```

### 3.4 Jobs

#### ParseYandexCompanyJob
```php
namespace App\Jobs;

class ParseYandexCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public int $tries = 3;
    public int $timeout = 300; // 5 минут
    
    public function __construct(
        public readonly int $companyId
    ) {}
    
    public function handle(YandexMapParserService $parser): void
    {
        $company = Company::findOrFail($this->companyId);
        $company->update(['parse_status' => 'processing']);
        
        try {
            $result = $parser->parse($company->yandex_url);
            
            // Сохранение данных компании
            $company->update([
                'yandex_org_id' => $result->companyData->yandexOrgId,
                'name' => $result->companyData->name,
                'rating' => $result->companyData->rating,
                'reviews_count' => $result->companyData->reviewsCount,
                'ratings_count' => $result->companyData->ratingsCount,
                'parse_status' => 'completed',
                'last_parsed_at' => now()
            ]);
            
            // Сохранение отзывов с дедупликацией
            foreach ($result->reviews as $reviewDto) {
                Review::updateOrCreate(
                    ['external_id' => $reviewDto->externalId, 'company_id' => $company->id],
                    [
                        'author_name' => $reviewDto->authorName,
                        'author_avatar' => $reviewDto->authorAvatar,
                        'rating' => $reviewDto->rating,
                        'text' => $reviewDto->text,
                        'review_created_at' => $reviewDto->reviewCreatedAt,
                        'raw_payload_hash' => $result->rawPayloadHash
                    ]
                );
            }
            
            // Логирование успеха
            ParseLog::create([
                'company_id' => $company->id,
                'parse_job_id' => $this->job->getJobId(),
                'status' => 'completed',
                'payload_snapshot' => ['hash' => $result->rawPayloadHash],
                'reviews_collected' => count($result->reviews)
            ]);
            
        } catch (\Exception $e) {
            $company->update(['parse_status' => 'failed']);
            
            ParseLog::create([
                'company_id' => $company->id,
                'parse_job_id' => $this->job->getJobId(),
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}
```

### 3.5 Controllers

#### AuthController
```php
namespace App\Http\Controllers;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        if (Auth::attempt($request->only('email', 'password'))) {
            $request->session()->regenerate();
            return response()->json(['user' => Auth::user()]);
        }
        
        return response()->json(['error' => 'Invalid credentials'], 401);
    }
    
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return response()->json(['message' => 'Logged out']);
    }
    
    public function user(Request $request)
    {
        return response()->json($request->user());
    }
}
```

#### CompanyController
```php
namespace App\Http\Controllers;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $companies = $request->user()->companies()
            ->withCount('reviews')
            ->orderBy('created_at', 'desc')
            ->paginate(20);
            
        return response()->json($companies);
    }
    
    public function store(StoreCompanyRequest $request)
    {
        $company = $request->user()->companies()->create([
            'yandex_url' => $request->yandex_url,
            'parse_status' => 'pending'
        ]);
        
        // Запуск парсинга
        ParseYandexCompanyJob::dispatch($company->id);
        
        return response()->json($company, 201);
    }
    
    public function show(Request $request, Company $company)
    {
        $this->authorize('view', $company);
        
        return response()->json($company->load('reviews'));
    }
    
    public function destroy(Request $request, Company $company)
    {
        $this->authorize('delete', $company);
        
        $company->delete();
        
        return response()->json(['message' => 'Company deleted']);
    }
    
    public function parse(Request $request, Company $company)
    {
        $this->authorize('update', $company);
        
        $company->update(['parse_status' => 'pending']);
        ParseYandexCompanyJob::dispatch($company->id);
        
        return response()->json(['message' => 'Parse job dispatched']);
    }
}
```

#### ReviewController
```php
namespace App\Http\Controllers;

class ReviewController extends Controller
{
    public function index(Request $request, Company $company)
    {
        $this->authorize('view', $company);
        
        $query = $company->reviews();
        
        // Фильтры
        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }
        
        if ($request->has('date_from')) {
            $query->where('review_created_at', '>=', $request->date_from);
        }
        
        if ($request->has('date_to')) {
            $query->where('review_created_at', '<=', $request->date_to);
        }
        
        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('text', 'like', "%{$request->search}%")
                  ->orWhere('author_name', 'like', "%{$request->search}%");
            });
        }
        
        $reviews = $query->orderBy('review_created_at', 'desc')
            ->paginate($request->per_page ?? 50);
            
        return response()->json($reviews);
    }
}
```

## 4. API Contracts

### 4.1 Authentication Endpoints

#### POST /api/login
**Request:**
```json
{
  "email": "admin@example.com",
  "password": "password"
}
```

**Response (200):**
```json
{
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@example.com"
  }
}
```

**Response (401):**
```json
{
  "error": "Invalid credentials"
}
```

#### POST /api/logout
**Headers:** `Authorization: Bearer {token}` или Cookie session

**Response (200):**
```json
{
  "message": "Logged out"
}
```

#### GET /api/user
**Headers:** `Authorization: Bearer {token}` или Cookie session

**Response (200):**
```json
{
  "id": 1,
  "name": "Admin",
  "email": "admin@example.com"
}
```

### 4.2 Company Endpoints

#### POST /api/companies
**Headers:** `Authorization: Bearer {token}` или Cookie session

**Request:**
```json
{
  "yandex_url": "https://yandex.ru/maps/123/moscow/organization/456/reviews/"
}
```

**Response (201):**
```json
{
  "id": 1,
  "yandex_url": "https://yandex.ru/maps/123/moscow/organization/456/reviews/",
  "yandex_org_id": "456",
  "name": null,
  "rating": null,
  "reviews_count": 0,
  "ratings_count": 0,
  "parse_status": "pending",
  "last_parsed_at": null,
  "created_at": "2026-09-11T21:00:00.000000Z",
  "updated_at": "2026-09-11T21:00:00.000000Z"
}
```

#### GET /api/companies
**Headers:** `Authorization: Bearer {token}` или Cookie session

**Query Params:** `page=1`

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "yandex_url": "https://yandex.ru/maps/123/moscow/organization/456/reviews/",
      "yandex_org_id": "456",
      "name": "Test Company",
      "rating": 4.5,
      "reviews_count": 150,
      "ratings_count": 200,
      "parse_status": "completed",
      "last_parsed_at": "2026-09-11T20:00:00.000000Z",
      "created_at": "2026-09-11T19:00:00.000000Z",
      "updated_at": "2026-09-11T20:00:00.000000Z",
      "reviews_count": 150
    }
  ],
  "current_page": 1,
  "per_page": 20,
  "total": 1
}
```

#### GET /api/companies/{id}
**Headers:** `Authorization: Bearer {token}` или Cookie session

**Response (200):**
```json
{
  "id": 1,
  "yandex_url": "https://yandex.ru/maps/123/moscow/organization/456/reviews/",
  "yandex_org_id": "456",
  "name": "Test Company",
  "rating": 4.5,
  "reviews_count": 150,
  "ratings_count": 200,
  "parse_status": "completed",
  "last_parsed_at": "2026-09-11T20:00:00.000000Z",
  "created_at": "2026-09-11T19:00:00.000000Z",
  "updated_at": "2026-09-11T20:00:00.000000Z",
  "reviews": [...]
}
```

#### DELETE /api/companies/{id}
**Headers:** `Authorization: Bearer {token}` или Cookie session

**Response (200):**
```json
{
  "message": "Company deleted"
}
```

#### POST /api/companies/{id}/parse
**Headers:** `Authorization: Bearer {token}` или Cookie session

**Response (200):**
```json
{
  "message": "Parse job dispatched"
}
```

### 4.3 Review Endpoints

#### GET /api/companies/{id}/reviews
**Headers:** `Authorization: Bearer {token}` или Cookie session

**Query Params:** 
- `page=1`
- `per_page=50`
- `rating=5` (опционально)
- `date_from=2026-01-01` (опционально)
- `date_to=2026-12-31` (опционально)
- `search=text` (опционально)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "company_id": 1,
      "external_id": "rev_123",
      "author_name": "John Doe",
      "author_avatar": "https://example.com/avatar.jpg",
      "rating": 5,
      "text": "Great service!",
      "review_created_at": "2026-09-10T15:30:00.000000Z",
      "created_at": "2026-09-11T20:00:00.000000Z",
      "updated_at": "2026-09-11T20:00:00.000000Z"
    }
  ],
  "current_page": 1,
  "per_page": 50,
  "total": 150
}
```

## 5. Frontend Structure (./frontend)

### 5.1 Directory Structure
```
frontend/
├── src/
│   ├── assets/
│   │   └── main.css
│   ├── components/
│   │   ├── CompanyStats.vue
│   │   ├── ReviewList.vue
│   │   ├── Pagination.vue
│   │   ├── UrlForm.vue
│   │   └── ProgressBar.vue
│   ├── composables/
│   │   ├── useAuth.js
│   │   └── useParser.js
│   ├── router/
│   │   └── index.js
│   ├── stores/
│   │   ├── auth.js
│   │   └── company.js
│   ├── views/
│   │   ├── LoginView.vue
│   │   └── DashboardView.vue
│   ├── App.vue
│   └── main.js
├── public/
├── index.html
├── package.json
├── vite.config.js
└── tailwind.config.js
```

### 5.2 Pinia Stores

#### auth.js
```javascript
import { defineStore } from 'pinia'
import axios from 'axios'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('token') || null
  }),
  
  actions: {
    async login(email, password) {
      const response = await axios.post('/api/login', { email, password })
      this.user = response.data.user
      localStorage.setItem('token', 'session-based')
    },
    
    async logout() {
      await axios.post('/api/logout')
      this.user = null
      this.token = null
      localStorage.removeItem('token')
    },
    
    async fetchUser() {
      const response = await axios.get('/api/user')
      this.user = response.data
    }
  }
})
```

#### company.js
```javascript
import { defineStore } from 'pinia'
import axios from 'axios'

export const useCompanyStore = defineStore('company', {
  state: () => ({
    companies: [],
    currentCompany: null,
    reviews: [],
    pagination: {
      page: 1,
      per_page: 50,
      total: 0
    },
    filters: {
      rating: null,
      date_from: null,
      date_to: null,
      search: ''
    }
  }),
  
  actions: {
    async fetchCompanies() {
      const response = await axios.get('/api/companies')
      this.companies = response.data.data
    },
    
    async createCompany(yandexUrl) {
      const response = await axios.post('/api/companies', { yandex_url: yandexUrl })
      this.companies.unshift(response.data)
      return response.data
    },
    
    async deleteCompany(id) {
      await axios.delete(`/api/companies/${id}`)
      this.companies = this.companies.filter(c => c.id !== id)
    },
    
    async startParse(companyId) {
      await axios.post(`/api/companies/${companyId}/parse`)
      const company = this.companies.find(c => c.id === companyId)
      if (company) company.parse_status = 'pending'
    },
    
    async fetchReviews(companyId, page = 1) {
      const params = {
        page,
        per_page: this.pagination.per_page,
        ...this.filters
      }
      
      const response = await axios.get(`/api/companies/${companyId}/reviews`, { params })
      this.reviews = response.data.data
      this.pagination = {
        page: response.data.current_page,
        per_page: response.data.per_page,
        total: response.data.total
      }
    }
  }
})
```

### 5.3 Router Configuration

#### router/index.js
```javascript
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { requiresGuest: true }
  },
  {
    path: '/dashboard',
    name: 'dashboard',
    component: () => import('../views/DashboardView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/',
    redirect: '/dashboard'
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()
  
  if (to.meta.requiresAuth && !authStore.user) {
    next('/login')
  } else if (to.meta.requiresGuest && authStore.user) {
    next('/dashboard')
  } else {
    next()
  }
})

export default router
```

### 5.4 Views

#### LoginView.vue
```vue
<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref(null)

const handleLogin = async () => {
  error.value = null
  try {
    await authStore.login(email.value, password.value)
    router.push('/dashboard')
  } catch (e) {
    error.value = 'Invalid credentials'
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full">
      <h1 class="text-2xl font-bold mb-6">Login</h1>
      
      <form @submit.prevent="handleLogin">
        <div class="mb-4">
          <label class="block mb-2">Email</label>
          <input v-model="email" type="email" class="w-full border rounded px-3 py-2" required />
        </div>
        
        <div class="mb-4">
          <label class="block mb-2">Password</label>
          <input v-model="password" type="password" class="w-full border rounded px-3 py-2" required />
        </div>
        
        <div v-if="error" class="text-red-500 mb-4">{{ error }}</div>
        
        <button type="submit" class="w-full bg-blue-500 text-white py-2 rounded">
          Login
        </button>
      </form>
    </div>
  </div>
</template>
```

#### DashboardView.vue
```vue
<script setup>
import { onMounted, ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useCompanyStore } from '../stores/company'
import CompanyStats from '../components/CompanyStats.vue'
import ReviewList from '../components/ReviewList.vue'
import UrlForm from '../components/UrlForm.vue'

const authStore = useAuthStore()
const companyStore = useCompanyStore()

const selectedCompany = ref(null)

onMounted(async () => {
  await companyStore.fetchCompanies()
})

const selectCompany = (company) => {
  selectedCompany.value = company
  companyStore.fetchReviews(company.id)
}

const handleParse = async (companyId) => {
  await companyStore.startParse(companyId)
  // Запуск polling для обновления статуса
}
</script>

<template>
  <div class="min-h-screen">
    <header class="bg-white shadow">
      <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
        <h1 class="text-xl font-bold">Yandex Reviews Parser</h1>
        <button @click="authStore.logout" class="text-red-500">Logout</button>
      </div>
    </header>
    
    <main class="max-w-7xl mx-auto px-4 py-8">
      <UrlForm @created="companyStore.fetchCompanies" />
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-8">
        <div>
          <h2 class="text-lg font-semibold mb-4">Companies</h2>
          <div v-for="company in companyStore.companies" :key="company.id"
               @click="selectCompany(company)"
               class="p-4 border rounded cursor-pointer hover:bg-gray-50"
               :class="{ 'border-blue-500': selectedCompany?.id === company.id }">
            <CompanyStats :company="company" @parse="handleParse" />
          </div>
        </div>
        
        <div v-if="selectedCompany">
          <h2 class="text-lg font-semibold mb-4">Reviews</h2>
          <ReviewList :company="selectedCompany" />
        </div>
      </div>
    </main>
  </div>
</template>
```

### 5.5 Components

#### UrlForm.vue
```vue
<script setup>
import { ref } from 'vue'
import { useCompanyStore } from '../stores/company'

const companyStore = useCompanyStore()
const yandexUrl = ref('')
const error = ref(null)

const handleSubmit = async () => {
  error.value = null
  try {
    await companyStore.createCompany(yandexUrl.value)
    yandexUrl.value = ''
  } catch (e) {
    error.value = e.response?.data?.message || 'Error creating company'
  }
}
</script>

<template>
  <div class="bg-white p-6 rounded shadow">
    <h2 class="text-lg font-semibold mb-4">Add Company</h2>
    
    <form @submit.prevent="handleSubmit">
      <div class="mb-4">
        <label class="block mb-2">Yandex Maps URL</label>
        <input v-model="yandexUrl" type="url" 
               class="w-full border rounded px-3 py-2"
               placeholder="https://yandex.ru/maps/..." required />
      </div>
      
      <div v-if="error" class="text-red-500 mb-4">{{ error }}</div>
      
      <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">
        Add Company
      </button>
    </form>
  </div>
</template>
```

#### CompanyStats.vue
```vue
<script setup>
const props = defineProps({
  company: Object
})

const emit = defineEmits(['parse'])

const statusColors = {
  idle: 'gray',
  pending: 'yellow',
  processing: 'blue',
  completed: 'green',
  failed: 'red'
}
</script>

<template>
  <div>
    <h3 class="font-medium">{{ company.name || 'Unnamed Company' }}</h3>
    <div class="text-sm text-gray-600 mt-1">
      Rating: {{ company.rating || 'N/A' }} ({{ company.reviews_count }} reviews)
    </div>
    <div class="flex items-center gap-2 mt-2">
      <span :class="`px-2 py-1 rounded text-xs bg-${statusColors[company.parse_status]}-100`">
        {{ company.parse_status }}
      </span>
      <button @click="emit('parse', company.id)" 
              class="text-blue-500 text-sm hover:underline">
        Parse
      </button>
    </div>
  </div>
</template>
```

#### ReviewList.vue
```vue
<script setup>
import { onMounted, ref, watch } from 'vue'
import { useCompanyStore } from '../stores/company'

const props = defineProps({
  company: Object
})

const companyStore = useCompanyStore()
const filters = ref({
  rating: null,
  date_from: null,
  date_to: null,
  search: ''
})

onMounted(() => {
  companyStore.fetchReviews(props.company.id)
})

watch(filters, () => {
  companyStore.filters = filters.value
  companyStore.fetchReviews(props.company.id)
}, { deep: true })

const handlePageChange = (page) => {
  companyStore.fetchReviews(props.company.id, page)
}
</script>

<template>
  <div class="bg-white p-6 rounded shadow">
    <div class="mb-4 flex gap-2">
      <select v-model="filters.rating" class="border rounded px-2 py-1">
        <option :value="null">All Ratings</option>
        <option v-for="i in 5" :key="i" :value="i">{{ i }} Stars</option>
      </select>
      
      <input v-model="filters.search" type="text" 
             class="border rounded px-2 py-1 flex-1"
             placeholder="Search reviews..." />
    </div>
    
    <div v-if="companyStore.reviews.length === 0" class="text-gray-500">
      No reviews found
    </div>
    
    <div v-else class="space-y-4">
      <div v-for="review in companyStore.reviews" :key="review.id" 
           class="border-b pb-4">
        <div class="flex items-center gap-2">
          <span class="font-medium">{{ review.author_name }}</span>
          <span class="text-yellow-500">{{ '★'.repeat(review.rating) }}</span>
        </div>
        <p class="text-gray-700 mt-1">{{ review.text }}</p>
        <div class="text-sm text-gray-500 mt-1">
          {{ new Date(review.review_created_at).toLocaleDateString() }}
        </div>
      </div>
    </div>
    
    <Pagination v-if="companyStore.pagination.total > 0"
                :pagination="companyStore.pagination"
                @page-change="handlePageChange" />
  </div>
</template>
```

#### Pagination.vue
```vue
<script setup>
const props = defineProps({
  pagination: Object
})

const emit = defineEmits(['pageChange'])

const totalPages = computed(() => Math.ceil(props.pagination.total / props.pagination.per_page))
</script>

<template>
  <div class="flex justify-center gap-2 mt-4">
    <button @click="emit('pageChange', pagination.page - 1)"
            :disabled="pagination.page === 1"
            class="px-3 py-1 border rounded disabled:opacity-50">
      Previous
    </button>
    
    <span class="px-3 py-1">
      Page {{ pagination.page }} of {{ totalPages }}
    </span>
    
    <button @click="emit('pageChange', pagination.page + 1)"
            :disabled="pagination.page === totalPages"
            class="px-3 py-1 border rounded disabled:opacity-50">
      Next
    </button>
  </div>
</template>
```

## 6. Docker & Deployment Plan

### 6.1 Docker Compose Configuration

#### docker-compose.yml
```yaml
version: '3.8'

services:
  # Backend Laravel
  app:
    build:
      context: ./backend
      dockerfile: Dockerfile
    container_name: laravel_app
    restart: unless-stopped
    working_dir: /var/www
    volumes:
      - ./backend:/var/www
    environment:
      - DB_HOST=mysql
      - DB_PORT=3306
      - DB_DATABASE=yandex_parser
      - DB_USERNAME=root
      - DB_PASSWORD=secret
      - REDIS_HOST=redis
      - REDIS_PORT=6379
    depends_on:
      - mysql
      - redis
    networks:
      - app-network

  # Nginx for Backend
  nginx:
    image: nginx:alpine
    container_name: laravel_nginx
    restart: unless-stopped
    ports:
      - "8000:80"
    volumes:
      - ./backend:/var/www
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app
    networks:
      - app-network

  # MySQL Database
  mysql:
    image: mysql:8.0
    container_name: laravel_mysql
    restart: unless-stopped
    environment:
      - MYSQL_DATABASE=yandex_parser
      - MYSQL_ROOT_PASSWORD=secret
      - MYSQL_USER=laravel
      - MYSQL_PASSWORD=secret
    ports:
      - "3306:3306"
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - app-network

  # Redis
  redis:
    image: redis:alpine
    container_name: laravel_redis
    restart: unless-stopped
    ports:
      - "6379:6379"
    networks:
      - app-network

  # Queue Worker
  queue-worker:
    build:
      context: ./backend
      dockerfile: Dockerfile
    container_name: laravel_queue_worker
    restart: unless-stopped
    command: php artisan queue:work --sleep=3 --tries=3
    working_dir: /var/www
    volumes:
      - ./backend:/var/www
    environment:
      - DB_HOST=mysql
      - DB_PORT=3306
      - DB_DATABASE=yandex_parser
      - DB_USERNAME=root
      - DB_PASSWORD=secret
      - REDIS_HOST=redis
      - REDIS_PORT=6379
    depends_on:
      - mysql
      - redis
    networks:
      - app-network

  # Frontend (Vue)
  frontend:
    build:
      context: ./frontend
      dockerfile: Dockerfile
    container_name: vue_frontend
    restart: unless-stopped
    ports:
      - "3000:80"
    depends_on:
      - nginx
    networks:
      - app-network

  # Playwright Service (фоллбэк парсер)
  playwright:
    image: mcr.microsoft.com/playwright:latest
    container_name: playwright_service
    restart: unless-stopped
    command: node /app/server.js
    volumes:
      - ./backend/services/playwright:/app
    ports:
      - "4000:4000"
    networks:
      - app-network

networks:
  app-network:
    driver: bridge

volumes:
  mysql_data:
```

### 6.2 Backend Dockerfile

#### backend/Dockerfile
```dockerfile
FROM php:8.2-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy application files
COPY . /var/www

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www
RUN chmod -R 755 /var/www/storage

EXPOSE 9000

CMD ["php-fpm"]
```

### 6.3 Frontend Dockerfile

#### frontend/Dockerfile
```dockerfile
# Build stage
FROM node:18-alpine as build

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .
RUN npm run build

# Production stage
FROM nginx:alpine

COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
```

### 6.4 Nginx Configuration

#### docker/nginx/default.conf (Backend)
```nginx
server {
    listen 80;
    index index.php index.html;
    error_log  /var/log/nginx/error.log;
    access_log /var/log/nginx/access.log;

    root /var/www/public;

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
        gzip_static on;
    }
}
```

#### frontend/nginx.conf (Frontend)
```nginx
server {
    listen 80;
    root /usr/share/nginx/html;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api {
        proxy_pass http://nginx:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

### 6.5 Playwright Service

#### backend/services/playwright/server.js
```javascript
const express = require('express');
const { chromium } = require('playwright');
const app = express();
app.use(express.json());

app.post('/parse', async (req, res) => {
  const { url } = req.body;
  
  try {
    const browser = await chromium.launch();
    const page = await browser.newPage();
    await page.goto(url);
    
    // Парсинг данных из DOM
    const data = await page.evaluate(() => {
      // Логика извлечения данных
      return { reviews: [], companyInfo: {} };
    });
    
    await browser.close();
    res.json(data);
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

app.listen(4000, () => {
  console.log('Playwright service running on port 4000');
});
```

### 6.6 Deployment Steps

#### Local Development
```bash
# Клонирование репозитория
git clone <repo-url>
cd DailyGrowTestLaravelVueYandexMaps

# Запуск Docker Compose
docker-compose up -d

# Выполнение миграций
docker-compose exec app php artisan migrate
docker-compose exec app php artisan db:seed

# Установка фронтенда зависимостей
docker-compose exec frontend npm install
docker-compose exec frontend npm run build
```

#### Production Deployment
1. **Настройка окружения**
   - Создать `.env` файл с production настройками
   - Настроить SSL сертификаты
   - Настроить firewall

2. **База данных**
   - Использовать managed MySQL (RDS, Cloud SQL)
   - Настроить backups

3. **Queue Workers**
   - Настроить Supervisor для управления workers
   - Настроить мониторинг очередей

4. **Мониторинг**
   - Логи: Docker logs, Laravel Telescope
   - Метрики: Prometheus + Grafana
   - Alerts: при ошибках парсера

5. **CI/CD**
   - GitHub Actions для автоматического деплоя
   - Тесты перед деплоем
   - Rollback стратегия

### 6.7 Environment Variables

#### backend/.env
```env
APP_NAME="Yandex Parser"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=yandex_parser
DB_USERNAME=root
DB_PASSWORD=secret

BROADCAST_DRIVER=log
CACHE_DRIVER=redis
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

SANCTUM_STATEFUL_DOMAINS=localhost:3000
```

#### frontend/.env
```env
VITE_API_URL=http://localhost:8000
```

## 7. Testing Strategy

### 7.1 Backend Tests
- Unit тесты для Services и DTOs
- Integration тесты для API endpoints
- Queue Job тесты
- Parser тесты с mock данными

### 7.2 Frontend Tests
- Component тесты (Vitest)
- E2E тесты (Playwright/Cypress)
- Store тесты

### 7.3 Integration Tests
- Тест полного цикла парсинга
- Тест дедупликации отзывов
- Тест обработки ошибок
