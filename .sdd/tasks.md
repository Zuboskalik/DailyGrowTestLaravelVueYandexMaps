# Implementation Tasks Checklist

## Phase 1: Infrastructure & Environment Setup

- [ ] **Task 1.1: Root Docker Compose Configuration** (Root)
  - **Acceptance Criteria:**
    - docker-compose.yml создан в корне проекта
    - Сервисы: app (Laravel), nginx, mysql, redis, queue-worker, frontend, playwright
    - Все сервисы имеют правильные зависимости и network конфигурацию
    - Volumes настроены для persistent данных (mysql)
  - **Files:** `docker-compose.yml`

- [ ] **Task 1.2: Backend Dockerfile & Configuration** (./backend)
  - **Acceptance Criteria:**
    - Dockerfile создан для PHP 8.2-FPM
    - Установлены необходимые PHP extensions (pdo_mysql, mbstring, gd, bcmath)
    - Composer установлен
    - Правильные permissions для /var/www/storage
  - **Files:** `backend/Dockerfile`

- [ ] **Task 1.3: Frontend Dockerfile & Nginx Config** (./frontend)
  - **Acceptance Criteria:**
    - Multi-stage Dockerfile (build + production)
    - Nginx configuration для SPA (fallback to index.html)
    - Proxy настроен для /api requests к backend
  - **Files:** `frontend/Dockerfile`, `frontend/nginx.conf`

- [ ] **Task 1.4: Backend Nginx Configuration** (Root)
  - **Acceptance Criteria:**
    - Nginx config для PHP-FPM
    - Правильные fastcgi params
    - CORS headers для Sanctum
  - **Files:** `docker/nginx/default.conf`

- [ ] **Task 1.5: Environment Configuration Files** (./backend)
  - **Acceptance Criteria:**
    - .env.example создан с всеми необходимыми переменными
    - .env создан для local development
    - Настроены DB, Redis, Sanctum параметры
  - **Files:** `backend/.env.example`, `backend/.env`

- [ ] **Task 1.6: Frontend Environment Configuration** (./frontend)
  - **Acceptance Criteria:**
    - .env.example создан
    - .env создан с VITE_API_URL
  - **Files:** `frontend/.env.example`, `frontend/.env`

## Phase 2: Backend - Core Setup

- [ ] **Task 2.1: Laravel Project Initialization** (./backend)
  - **Acceptance Criteria:**
    - Laravel 11 проект инициализирован
    - Composer dependencies установлены
    - composer.json настроен
  - **Files:** `backend/composer.json`, `backend/composer.lock`

- [ ] **Task 2.2: Database Migrations - Users Table** (./backend)
  - **Acceptance Criteria:**
    - Migration создана для users таблицы
    - Поля: id, name, email, password, email_verified_at, remember_token, timestamps
    - Email уникальный индекс
  - **Files:** `backend/database/migrations/xxxx_xx_xx_create_users_table.php`

- [ ] **Task 2.3: Database Migrations - Companies Table** (./backend)
  - **Acceptance Criteria:**
    - Migration создана для companies таблицы
    - Поля: id, yandex_url, yandex_org_id, name, rating, reviews_count, ratings_count, parse_status, last_parsed_at, timestamps
    - Индексы: yandex_org_id, parse_status
    - Enum для parse_status: idle, pending, processing, completed, failed
  - **Files:** `backend/database/migrations/xxxx_xx_xx_create_companies_table.php`

- [ ] **Task 2.4: Database Migrations - Reviews Table** (./backend)
  - **Acceptance Criteria:**
    - Migration создана для reviews таблицы
    - Поля: id, company_id (foreign key), external_id (unique), author_name, author_avatar, rating, text, review_created_at, raw_payload_hash, timestamps
    - Индексы: [company_id, external_id], review_created_at, rating
    - Foreign key с cascade delete
  - **Files:** `backend/database/migrations/xxxx_xx_xx_create_reviews_table.php`

- [ ] **Task 2.5: Database Migrations - Parse Logs Table** (./backend)
  - **Acceptance Criteria:**
    - Migration создана для parse_logs таблицы
    - Поля: id, company_id (foreign key), parse_job_id (foreign key to jobs), status, error_message, payload_snapshot (json), reviews_collected, timestamps
    - Индексы: company_id, status
  - **Files:** `backend/database/migrations/xxxx_xx_xx_create_parse_logs_table.php`

- [ ] **Task 2.6: Database Migrations - Jobs Table** (./backend)
  - **Acceptance Criteria:**
    - Laravel queue jobs table создана
    - php artisan queue:table выполнен
  - **Files:** `backend/database/migrations/xxxx_xx_xx_create_jobs_table.php`

- [ ] **Task 2.7: User Model with Relationships** (./backend)
  - **Acceptance Criteria:**
    - User model создан
    - Relationship: hasMany(Company::class)
    - Implements Authenticatable contract
  - **Files:** `backend/app/Models/User.php`

- [ ] **Task 2.8: Company Model with Relationships** (./backend)
  - **Acceptance Criteria:**
    - Company model создан
    - Relationship: belongsTo(User::class), hasMany(Review::class), hasMany(ParseLog::class)
    - Casts для rating (decimal), parse_status (enum)
    - Fillable поля определены
  - **Files:** `backend/app/Models/Company.php`

- [ ] **Task 2.9: Review Model with Relationships** (./backend)
  - **Acceptance Criteria:**
    - Review model создан
    - Relationship: belongsTo(Company::class)
    - Casts для review_created_at (datetime), rating (integer)
    - Fillable поля определены
  - **Files:** `backend/app/Models/Review.php`

- [ ] **Task 2.10: ParseLog Model with Relationships** (./backend)
  - **Acceptance Criteria:**
    - ParseLog model создан
    - Relationship: belongsTo(Company::class), belongsTo(Job::class)
    - Casts для payload_snapshot (json), status (enum)
    - Fillable поля определены
  - **Files:** `backend/app/Models/ParseLog.php`

- [ ] **Task 2.11: Database Seeder - Admin User** (./backend)
  - **Acceptance Criteria:**
    - Seeder создан для admin пользователя
    - Email: admin@example.com, Password: password
    - User создан при выполнении php artisan db:seed
  - **Files:** `backend/database/seeders/AdminUserSeeder.php`

## Phase 3: Backend - Authentication

- [ ] **Task 3.1: Sanctum Installation & Configuration** (./backend)
  - **Acceptance Criteria:**
    - Laravel Sanctum установлен
    - config/sanctum.php настроен
    - config/cors.php настроен для frontend origin
    - SANCTUM_STATEFUL_DOMAINS настроен в .env
  - **Files:** `backend/config/sanctum.php`, `backend/config/cors.php`

- [ ] **Task 3.2: Auth Controller - Login** (./backend)
  - **Acceptance Criteria:**
    - AuthController создан
    - login() метод реализован
    - Использует Auth::attempt()
    - Регенерирует session
    - Возвращает user data или 401
  - **Files:** `backend/app/Http/Controllers/AuthController.php`

- [ ] **Task 3.3: Auth Controller - Logout & User** (./backend)
  - **Acceptance Criteria:**
    - logout() метод реализован
    - user() метод реализован
    - Session invalidation при logout
  - **Files:** `backend/app/Http/Controllers/AuthController.php`

- [ ] **Task 3.4: API Routes - Auth Endpoints** (./backend)
  - **Acceptance Criteria:**
    - POST /api/login маршрут создан
    - POST /api/logout маршрут создан
    - GET /api/user маршрут создан
    - Маршруты в api.php
  - **Files:** `backend/routes/api.php`

- [ ] **Task 3.5: Sanctum Middleware Configuration** (./backend)
  - **Acceptance Criteria:**
    - sanctum middleware добавлен в api middleware group
    - EnsureFrontendRequestsAreStateful настроен
  - **Files:** `backend/bootstrap/app.php`, `backend/config/sanctum.php`

## Phase 4: Backend - Parser Services

- [ ] **Task 4.1: Parser Interface Contract** (./backend)
  - **Acceptance Criteria:**
    - ParserInterface создан
    - Методы: parse(string $url): ParseResultDTO, extractOrgId(string $url): string
  - **Files:** `backend/app/Services/YandexParser/Contracts/ParserInterface.php`

- [ ] **Task 4.2: DTOs - CompanyDataDTO** (./backend)
  - **Acceptance Criteria:**
    - CompanyDataDTO создан
    - Поля: yandexOrgId, name, rating, reviewsCount, ratingsCount
    - Readonly свойства
  - **Files:** `backend/app/DTOs/CompanyDataDTO.php`

- [ ] **Task 4.3: DTOs - ReviewDTO** (./backend)
  - **Acceptance Criteria:**
    - ReviewDTO создан
    - Поля: externalId, authorName, authorAvatar, rating, text, reviewCreatedAt
    - Readonly свойства
  - **Files:** `backend/app/DTOs/ReviewDTO.php`

- [ ] **Task 4.4: DTOs - ParseResultDTO** (./backend)
  - **Acceptance Criteria:**
    - ParseResultDTO создан
    - Поля: companyData (CompanyDataDTO), reviews (array of ReviewDTO), rawPayloadHash
    - Readonly свойства
  - **Files:** `backend/app/DTOs/ParseResultDTO.php`

- [ ] **Task 4.5: Yandex AJAX Parser Service** (./backend)
  - **Acceptance Criteria:**
    - YandexAjaxParser создан
    - Реализует ParserInterface
    - Использует Guzzle для HTTP запросов
    - Извлекает org_id из URL
    - Парсит AJAX endpoints Яндекса
    - Возвращает ParseResultDTO
    - Обрабатывает ошибки сети и валидации
  - **Files:** `backend/app/Services/YandexParser/YandexAjaxParser.php`

- [ ] **Task 4.6: Yandex Playwright Parser Service** (./backend)
  - **Acceptance Criteria:**
    - YandexPlaywrightParser создан
    - Реализует ParserInterface
    - Использует HTTP запросы к playwright service
    - Извлекает данные из DOM через playwright
    - Возвращает ParseResultDTO
    - Фоллбэк при недоступности AJAX
  - **Files:** `backend/app/Services/YandexParser/YandexPlaywrightParser.php`

- [ ] **Task 4.7: Main Parser Service** (./backend)
  - **Acceptance Criteria:**
    - YandexMapParserService создан
    - Инжектит YandexAjaxParser и YandexPlaywrightParser
    - Сначала пробует AJAX, при ошибке фоллбэк на Playwright
    - Логирует метод парсинга
  - **Files:** `backend/app/Services/YandexParser/YandexMapParserService.php`

- [ ] **Task 4.8: Playwright Node.js Service** (./backend)
  - **Acceptance Criteria:**
    - Express server создан
    - POST /parse endpoint
    - Использует Playwright chromium
    - Извлекает отзывы и данные компании из DOM
    - Возвращает JSON
  - **Files:** `backend/services/playwright/server.js`, `backend/services/playwright/package.json`

## Phase 5: Backend - Queue Jobs

- [ ] **Task 5.1: ParseYandexCompanyJob** (./backend)
  - **Acceptance Criteria:**
    - Job создан implements ShouldQueue
    - Принимает companyId
    - Обновляет статус компании на processing
    - Вызывает YandexMapParserService
    - Сохраняет данные компании
    - Сохраняет отзывы с дедупликацией (updateOrCreate по external_id)
    - Обновляет статус на completed или failed
    - Создает ParseLog запись
    - Тries: 3, Timeout: 300
  - **Files:** `backend/app/Jobs/ParseYandexCompanyJob.php`

- [ ] **Task 5.2: Queue Configuration** (./backend)
  - **Acceptance Criteria:**
    - config/queue.php настроен на database или redis
    - .env переменные QUEUE_CONNECTION настроены
  - **Files:** `backend/config/queue.php`, `backend/.env`

- [ ] **Task 5.3: Queue Worker Docker Service** (Root)
  - **Acceptance Criteria:**
    - queue-worker сервис в docker-compose.yml
    - Команда: php artisan queue:work --sleep=3 --tries=3
    - Зависимости: mysql, redis
  - **Files:** `docker-compose.yml`

## Phase 6: Backend - API Controllers

- [ ] **Task 6.1: CompanyController - Index & Store** (./backend)
  - **Acceptance Criteria:**
    - index() - возвращает компании пользователя с пагинацией
    - store() - создает компанию, валидирует URL, запускает ParseJob
    - Валидация StoreCompanyRequest
  - **Files:** `backend/app/Http/Controllers/CompanyController.php`

- [ ] **Task 6.2: CompanyController - Show, Destroy, Parse** (./backend)
  - **Acceptance Criteria:**
    - show() - возвращает компанию с отзывами
    - destroy() - удаляет компанию
    - parse() - запускает ParseJob для компании
    - Policy для авторизации
  - **Files:** `backend/app/Http/Controllers/CompanyController.php`

- [ ] **Task 6.3: ReviewController - Index with Filters** (./backend)
  - **Acceptance Criteria:**
    - index() - возвращает отзывы компании с пагинацией
    - Фильтры: rating, date_from, date_to, search
    - Сортировка по review_created_at DESC
    - per_page по умолчанию 50
  - **Files:** `backend/app/Http/Controllers/ReviewController.php`

- [ ] **Task 6.4: Form Request Validation** (./backend)
  - **Acceptance Criteria:**
    - LoginRequest создан
    - StoreCompanyRequest создан с URL валидацией
    - Правила валидации для Yandex URL
  - **Files:** `backend/app/Http/Requests/LoginRequest.php`, `backend/app/Http/Requests/StoreCompanyRequest.php`

- [ ] **Task 6.5: API Routes - Company & Review Endpoints** (./backend)
  - **Acceptance Criteria:**
    - GET/POST /api/companies маршруты
    - GET/DELETE /api/companies/{id} маршруты
    - POST /api/companies/{id}/parse маршрут
    - GET /api/companies/{id}/reviews маршрут
    - Все маршруты защищены sanctum middleware
  - **Files:** `backend/routes/api.php`

- [ ] **Task 6.6: Company Policy** (./backend)
  - **Acceptance Criteria:**
    - CompanyPolicy создан
    - view, update, delete методы
    - Проверка принадлежности компании пользователю
  - **Files:** `backend/app/Policies/CompanyPolicy.php`

## Phase 7: Frontend - Core Setup

- [ ] **Task 7.1: Vue 3 Project Initialization** (./frontend)
  - **Acceptance Criteria:**
    - Vue 3 проект создан с Vite
    - package.json настроен
    - vite.config.js настроен
  - **Files:** `frontend/package.json`, `frontend/vite.config.js`

- [ ] **Task 7.2: Frontend Dependencies Installation** (./frontend)
  - **Acceptance Criteria:**
    - Vue Router 4 установлен
    - Pinia установлена
    - Axios установлен
    - Tailwind CSS установлен
    - Все зависимости в package.json
  - **Files:** `frontend/package.json`

- [ ] **Task 7.3: Tailwind CSS Configuration** (./frontend)
  - **Acceptance Criteria:**
    - tailwind.config.js создан
    - content paths настроены
    - base.css с tailwind directives
  - **Files:** `frontend/tailwind.config.js`, `frontend/src/assets/base.css`

- [ ] **Task 7.4: Axios Configuration** (./frontend)
  - **Acceptance Criteria:**
    - Axios instance создан
    - baseURL настроен из VITE_API_URL
    - withCredentials: true для Sanctum
    - Interceptor для ошибок
  - **Files:** `frontend/src/utils/axios.js`

- [ ] **Task 7.5: Vue Router Configuration** (./frontend)
  - **Acceptance Criteria:**
    - router/index.js создан
    - Маршруты: /login, /dashboard, /
    - Navigation guard для auth
    - Meta fields: requiresAuth, requiresGuest
  - **Files:** `frontend/src/router/index.js`

- [ ] **Task 7.6: Main App Component** (./frontend)
  - **Acceptance Criteria:**
    - App.vue создан
    - RouterView настроен
    - Стили подключены
  - **Files:** `frontend/src/App.vue`

- [ ] **Task 7.7: Main Entry Point** (./frontend)
  - **Acceptance Criteria:**
    - main.js создан
    - App монтирован
    - Router и Pinia плагины подключены
    - Стили импортированы
  - **Files:** `frontend/src/main.js`

## Phase 8: Frontend - State Management

- [ ] **Task 8.1: Auth Store** (./frontend)
  - **Acceptance Criteria:**
    - useAuthStore создан
    - State: user, token
    - Actions: login, logout, fetchUser
    - Persist state в localStorage
  - **Files:** `frontend/src/stores/auth.js`

- [ ] **Task 8.2: Company Store** (./frontend)
  - **Acceptance Criteria:**
    - useCompanyStore создан
    - State: companies, currentCompany, reviews, pagination, filters
    - Actions: fetchCompanies, createCompany, deleteCompany, startParse, fetchReviews
    - Actions для фильтрации
  - **Files:** `frontend/src/stores/company.js`

## Phase 9: Frontend - Authentication UI

- [ ] **Task 9.1: Login View** (./frontend)
  - **Acceptance Criteria:**
    - LoginView.vue создан
    - Форма с email и password
    - Вызов authStore.login
    - Redirect на /dashboard при успехе
    - Отображение ошибок
  - **Files:** `frontend/src/views/LoginView.vue`

## Phase 10: Frontend - Dashboard UI

- [ ] **Task 10.1: Dashboard View Layout** (./frontend)
  - **Acceptance Criteria:**
    - DashboardView.vue создан
    - Header с логотипом и logout кнопкой
    - Основной layout с sidebar и content area
  - **Files:** `frontend/src/views/DashboardView.vue`

- [ ] **Task 10.2: UrlForm Component** (./frontend)
  - **Acceptance Criteria:**
    - UrlForm.vue создан
    - Форма с полем Yandex URL
    - Валидация URL формата
    - Вызов companyStore.createCompany
    - Отображение ошибок
  - **Files:** `frontend/src/components/UrlForm.vue`

- [ ] **Task 10.3: CompanyStats Component** (./frontend)
  - **Acceptance Criteria:**
    - CompanyStats.vue создан
    - Отображение названия компании
    - Отображение рейтинга и количества отзывов
    - Индикатор статуса парсинга с цветами
    - Кнопка "Parse"
  - **Files:** `frontend/src/components/CompanyStats.vue`

- [ ] **Task 10.4: Company List in Dashboard** (./frontend)
  - **Acceptance Criteria:**
    - Список компаний в DashboardView
    - Использует CompanyStats компонент
    - Выбор компании для просмотра отзывов
    - Загрузка компаний при mount
  - **Files:** `frontend/src/views/DashboardView.vue`

## Phase 11: Frontend - Reviews UI

- [ ] **Task 11.1: ReviewList Component** (./frontend)
  - **Acceptance Criteria:**
    - ReviewList.vue создан
    - Отображение списка отзывов
    - Карточка отзыва: автор, рейтинг, текст, дата
    - Фильтры: rating, search, date range
    - Интеграция с companyStore
  - **Files:** `frontend/src/components/ReviewList.vue`

- [ ] **Task 11.2: Pagination Component** (./frontend)
  - **Acceptance Criteria:**
    - Pagination.vue создан
    - Отображение текущей страницы и общего количества
    - Кнопки Previous/Next
    - Отключение кнопок при boundaries
    - Событие pageChange
  - **Files:** `frontend/src/components/Pagination.vue`

- [ ] **Task 11.3: ProgressBar Component** (./frontend)
  - **Acceptance Criteria:**
    - ProgressBar.vue создан
    - Отображение прогресса парсинга
    - Разные цвета для статусов
    - Анимация для processing
  - **Files:** `frontend/src/components/ProgressBar.vue`

- [ ] **Task 11.4: Reviews Integration in Dashboard** (./frontend)
  - **Acceptance Criteria:**
    - ReviewList интегрирован в DashboardView
    - Pagination интегрирован
    - Отображение при выборе компании
    - Polling для обновления статуса парсинга
  - **Files:** `frontend/src/views/DashboardView.vue`

## Phase 12: Integration & Testing

- [ ] **Task 12.1: Backend Integration Tests** (./backend)
  - **Acceptance Criteria:**
    - Tests для AuthController
    - Tests для CompanyController
    - Tests для ReviewController
    - Tests для ParseYandexCompanyJob
    - Tests для Parser Services с mocks
  - **Files:** `backend/tests/Feature/AuthTest.php`, `backend/tests/Feature/CompanyTest.php`, `backend/tests/Unit/ParserTest.php`

- [ ] **Task 12.2: Frontend Component Tests** (./frontend)
  - **Acceptance Criteria:**
    - Tests для LoginView
    - Tests для UrlForm
    - Tests для CompanyStats
    - Tests для ReviewList
    - Tests для Pagination
  - **Files:** `frontend/src/components/__tests__/LoginView.spec.js`, `frontend/src/components/__tests__/UrlForm.spec.js`

- [ ] **Task 12.3: Frontend Store Tests** (./frontend)
  - **Acceptance Criteria:**
    - Tests для authStore
    - Tests для companyStore
  - **Files:** `frontend/src/stores/__tests__/auth.spec.js`, `frontend/src/stores/__tests__/company.spec.js`

- [ ] **Task 12.4: End-to-End Testing** (./frontend)
  - **Acceptance Criteria:**
    - E2E тест для полного цикла: login -> add company -> parse -> view reviews
    - Использование Playwright или Cypress
  - **Files:** `frontend/e2e/fullFlow.spec.js`

## Phase 13: Documentation & Deployment

- [ ] **Task 13.1: README.md** (Root)
  - **Acceptance Criteria:**
    - README.md создан в корне
    - Описание проекта
    - Инструкция по установке и запуску
    - Инструкция по использованию
    - Docker команды
    - Технологический стек
  - **Files:** `README.md`

- [ ] **Task 13.2: Backend README** (./backend)
  - **Acceptance Criteria:**
    - README.md в backend директории
    - API документация
    - Структура проекта
    - Команды artisan
  - **Files:** `backend/README.md`

- [ ] **Task 13.3: Frontend README** (./frontend)
  - **Acceptance Criteria:**
    - README.md в frontend директории
    - Структура компонентов
    - Available stores
    - Команды npm
  - **Files:** `frontend/README.md`

- [ ] **Task 13.4: .gitignore Configuration** (Root)
  - **Acceptance Criteria:**
    - .gitignore создан
    - Игнорирует node_modules, vendor, .env, storage/logs
    - Игнорирует IDE файлы
  - **Files:** `.gitignore`

- [ ] **Task 13.5: Production Environment Config** (./backend)
  - **Acceptance Criteria:**
    - .env.production.example создан
    - Production настройки для APP_ENV, APP_DEBUG
    - Production DB настройки
  - **Files:** `backend/.env.production.example`

- [ ] **Task 13.6: CI/CD Configuration** (Root)
  - **Acceptance Criteria:**
    - .github/workflows/ci.yml создан
    - Tests запускаются на push
    - Docker build тест
  - **Files:** `.github/workflows/ci.yml`

## Phase 14: Final Polish

- [ ] **Task 14.1: Error Handling & Logging** (./backend)
  - **Acceptance Criteria:**
    - Глобальный exception handler
    - Логирование ошибок парсера
    - Логирование API ошибок
  - **Files:** `backend/app/Exceptions/Handler.php`

- [ ] **Task 14.2: Frontend Error Handling** (./frontend)
  - **Acceptance Criteria:**
    - Глобальный error handler для axios
    - Error boundary component
    - User-friendly error messages
  - **Files:** `frontend/src/utils/axios.js`, `frontend/src/components/ErrorBoundary.vue`

- [ ] **Task 14.3: Loading States** (./frontend)
  - **Acceptance Criteria:**
    - Loading spinners для async операций
    - Skeleton loaders для списков
    - Disabled states для кнопок при загрузке
  - **Files:** `frontend/src/components/LoadingSpinner.vue`, `frontend/src/components/SkeletonLoader.vue`

- [ ] **Task 14.4: Responsive Design** (./frontend)
  - **Acceptance Criteria:**
    - Адаптивный layout для mobile
    - Tailwind responsive классы
    - Тестирование на разных размерах экрана
  - **Files:** `frontend/src/views/DashboardView.vue`, `frontend/src/components/ReviewList.vue`

- [ ] **Task 14.5: Security Hardening** (./backend)
  - **Acceptance Criteria:**
    - CSRF protection настроен
    - XSS protection
    - SQL injection prevention (ORM)
    - Rate limiting для API
  - **Files:** `backend/config/cors.php`, `backend/app/Http/Middleware/ThrottleRequests.php`

- [ ] **Task 14.6: Performance Optimization** (./backend)
  - **Acceptance Criteria:**
    - Database query optimization (eager loading)
    - Indexes для частых запросов
    - Кеширование настроек
  - **Files:** `backend/app/Http/Controllers/CompanyController.php`, `backend/app/Http/Controllers/ReviewController.php`

- [ ] **Task 14.7: Final Integration Testing** (Root)
  - **Acceptance Criteria:**
    - Полный тестовый сценарий в Docker
    - Проверка всех user flows
    - Проверка error scenarios
  - **Files:** `tests/integration/fullFlow.test.sh`

## Total Tasks: 63

### Task Distribution:
- **Root**: 8 tasks
- **Backend**: 35 tasks
- **Frontend**: 20 tasks

### Estimated Timeline:
- **Phase 1-2 (Infrastructure & Core)**: 2-3 days
- **Phase 3-6 (Backend Features)**: 4-5 days
- **Phase 7-11 (Frontend Features)**: 3-4 days
- **Phase 12-14 (Testing & Polish)**: 2-3 days

**Total Estimated Time**: 11-15 days
