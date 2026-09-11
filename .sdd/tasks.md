# Чек-лист реализации

Декомпозиция [`plan.md`](./plan.md) на атомарные, проверяемые задачи. Каждая задача — отдельный коммит/PR-скоуп с чётким критерием готовности (DoD).

---

## Phase 1: Setup & Migrations (`./backend`)

- [x] **1.1** Инициализировать Laravel-проект в `./backend` (composer, `.env.example` с настройками MySQL: `127.0.0.1:3306`, `DailyGrowTestLaravel`, `mysql`/`mysql`).
  DoD: `php artisan serve` поднимается, `php artisan migrate` проходит без ошибок на базовых миграциях Laravel.
- [x] **1.2** Установить и настроить Laravel Sanctum (`composer require laravel/sanctum`, публикация конфига, `EnsureFrontendRequestsAreStateful` в `api` middleware group, `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` в `.env`).
  DoD: конфиг `config/sanctum.php` и `config/cors.php` (`supports_credentials => true`) присутствуют и соответствуют домену фронтенда (Vite dev-сервер).
- [x] **1.3** Миграция `create_companies_table` по схеме из plan.md §1.1 (`url`, `normalized_url`, `yandex_id` unique, `name`, `rating`, `reviews_count`, `ratings_count`, `parse_status` enum, `last_parsed_at`, `last_error`).
  DoD: `php artisan migrate` создаёт таблицу с указанными индексами (`unique(yandex_id)`, `index(normalized_url)`); проверено через `php artisan migrate:status` и просмотр схемы (`DESCRIBE companies`).
- [x] **1.4** Миграция `create_reviews_table` (`company_id` FK cascadeOnDelete, `external_id`, `author_name`, `rating`, `text`, `review_created_at`), с `unique(company_id, external_id)` и `index(company_id, review_created_at)`.
  DoD: миграция применяется; попытка вставить дубль `(company_id, external_id)` через `DB::table('reviews')->insert(...)` в tinker бросает ошибку уникальности.
- [x] **1.5** Миграция `create_parsing_logs_table` (`company_id` FK, `status` enum, `reviews_collected`, `error_type` enum, `error_message`, `started_at`, `finished_at`) с `index(company_id, created_at)`.
  DoD: миграция применяется без ошибок, индекс виден в схеме.
- [x] **1.6** Создать модели `Company`, `Review`, `ParsingLog` с отношениями (`Company::reviews()`, `Company::parsingLogs()`, `Review::company()`, `ParsingLog::company()`), `$casts` для enum/decimal/datetime-полей.
  DoD: `Company::factory()->create()->reviews` возвращает пустую коллекцию без ошибок; типы полей (`rating` как float, `parse_status` как enum/string) корректны при `dd()`.
- [x] **1.7** Фабрики (`CompanyFactory`, `ReviewFactory`, `ParsingLogFactory`) для тестовых данных.
  DoD: `Company::factory()->has(Review::factory()->count(5))->create()` создаёт компанию и 5 связанных отзывов без нарушения constraints.

---

## Phase 2: Auth API & Seeder (`./backend`)

- [x] **2.1** `UserSeeder`: создаёт единственного сид-пользователя с фиксированным email/паролем (например через `.env`-переменные `SEED_USER_EMAIL`/`SEED_USER_PASSWORD` с дефолтами для локальной разработки).
  DoD: `php artisan db:seed --class=UserSeeder` создаёт ровно одну запись в `users`; повторный запуск не создаёт дубликат (использует `firstOrCreate`).
- [x] **2.2** Настроить `web`-роуты логина/логаута через стандартный флоу Sanctum SPA (`/login`, `/logout`) с CSRF-защитой (`VerifyCsrfToken` активен для этих роутов).
  DoD: `POST /login` с валидными кредами сид-пользователя (после `GET /sanctum/csrf-cookie`) возвращает `204`/`200` и устанавливает сессионную cookie; повторный запрос к защищённому роуту с этой cookie проходит `auth:sanctum`.
- [x] **2.3** Роут `GET /api/user` (`auth:sanctum`), возвращает текущего пользователя.
  DoD: без cookie → `401`; с валидной cookie → `200` с телом `{id, name, email}`.
- [x] **2.4** Тест невалидного логина (edge case §1 spec.md): неверный пароль/email.
  DoD: Feature-тест `LoginTest` — неверные креды → `422`, сообщение не раскрывает, существует ли email; сессия не создаётся.
- [x] **2.5** Feature-тест на защиту всех будущих `/api/*` роутов: неавторизованный запрос к `GET /api/companies` (заглушка роута может временно возвращать `401` до Phase 4) → `401`.
  DoD: тест `AuthMiddlewareTest` зелёный.

---

## Phase 3: Yandex Parser Service & Job (`./backend`)

- [ ] **3.1** Определить DTO `OrganizationSnapshot` и `ReviewSnapshot` (простые readonly-объекты/массивы с полями из plan.md §1.3) в `app/DTO`.
  DoD: классы существуют, покрыты unit-тестом на корректную инициализацию полей.
- [ ] **3.2** Определить доменные исключения: `ParsingException` (базовое), `ParsingStructureChangedException`, `OrganizationNotFoundException`, `SourceBannedException`, `SourceTimeoutException` в `app/Exceptions/Parsing`.
  DoD: все наследуют `ParsingException`; unit-тест проверяет иерархию (`instanceof`).
- [ ] **3.3** Реализовать интерфейс `ReviewSourceStrategy` (контракт `resolveOrganization`, `fetchReviews`) и заглушку `HttpJsonStrategy` (перехват внутреннего JSON/AJAX-эндпоинта Яндекс.Карт, как описано в plan.md §1.2).
  DoD: интерфейс определён; `HttpJsonStrategy` инжектируется через DI-контейнер (`app/Providers/AppServiceProvider` binding), заменяем на мок в тестах.
- [ ] **3.4** Реализовать `YandexMapParserService::resolveOrganization()` — извлечение `yandex_id`/`name`/`rating`/`ratings_count` из ответа стратегии, резолв редиректов для коротких ссылок (edge case §4.11 spec.md).
  DoD: unit-тест с мок-стратегией: короткая ссылка резолвится в каноническую, `yandex_id` извлекается корректно; несуществующая организация → `OrganizationNotFoundException`.
- [ ] **3.5** Реализовать `YandexMapParserService::fetchReviews()` как генератор с лимитом `~600` (plan.md §3.1 spec.md), включая проверку правила «`ratings_count > 0` и `0` отзывов → `ParsingStructureChangedException`» (spec.md §3.3).
  DoD: unit-тесты: (а) мок отдаёт 600+ отзывов → генератор останавливается ровно на лимите; (б) мок с `ratings_count=10`, `0` отзывов → выбрасывается `ParsingStructureChangedException`; (в) `ratings_count=0`, `0` отзывов → не выбрасывается, штатное завершение (edge case §5 spec.md).
- [ ] **3.6** Реализовать User-Agent rotation и throttling (случайная задержка между постраничными запросами) внутри `HttpJsonStrategy` (plan.md §1.2, §3.1).
  DoD: unit/feature-тест с фейковым HTTP-клиентом (`Http::fake()`) подтверждает, что заголовок `User-Agent` берётся из пула и не идентичен на 100% запросов подряд (проверка ротации), задержка вызывается между страницами (через инъекцию sleeper-интерфейса, мокаемого в тесте — без реального `sleep()` в тестовом окружении).
- [ ] **3.7** Классификация HTTP-ошибок источника: `403`/капча → `SourceBannedException`, сетевой таймаут → `SourceTimeoutException`.
  DoD: unit-тесты на каждый случай с `Http::fake()`, эмулирующим соответствующий ответ/исключение.
- [ ] **3.8** Реализовать `ParseYandexCompanyJob` (`ShouldQueue`, очередь `parsing`, `$tries = 3`, `backoff()` из plan.md §1.3): оркестрация `resolveOrganization` → `fetchReviews` → upsert в `reviews` по мере получения → обновление `companies`/`parsing_logs`.
  DoD: Feature-тест с фейковой очередью (`Queue::fake()`) на диспетч; отдельный тест с реальным синхронным выполнением Job (`Bus::dispatchSync` или `Queue::assertPushed` + вызов `handle()` напрямую) через мок `YandexMapParserService`: успешный сценарий → `companies.parse_status = completed`, `reviews` содержит upsert'нутые записи, `parsing_logs.status = completed`.
- [ ] **3.9** Обработка исключений в `ParseYandexCompanyJob`: каждый тип `ParsingException` маппится в `parsing_logs.error_type` (`banned`/`timeout`/`structure_changed`/`not_found`), `companies.parse_status = failed`, `companies.last_error` заполняется.
  DoD: параметризованный Feature-тест (data provider) на все 4 типа исключений → корректный `error_type` в БД.
- [ ] **3.10** Настроить `ParsingStructureChangedException` как non-retryable (`$tries = 1` для этого случая либо явный `$this->fail($e)` без дальнейших попыток очереди, plan.md §3.2).
  DoD: тест подтверждает, что при этом исключении Job не переставляется в очередь повторно (нет второй попытки `attempts()`).
- [ ] **3.11** Реализовать идемпотентный upsert отзывов (`updateOrCreate` по `(company_id, external_id)`) и защиту от параллельного запуска (`WithoutOverlapping` middleware Job по `company_id`, edge case §4.8 spec.md).
  DoD: тест «повторный запуск с тем же набором `external_id`» → количество записей в `reviews` не увеличивается, только `updated_at` меняется у изменившихся; тест на попытку запустить второй Job для той же компании, пока первый не завершён — второй не выполняется параллельно (проверка через `Bus::fake()`/middleware assertion).

---

## Phase 4: Company & Review API endpoints (`./backend`)

- [ ] **4.1** `StoreCompanyRequest` с regex-валидацией ссылки под форматы из spec.md §2 (`/maps/org/...`, `?oid=...`, короткие ссылки `/maps/-/...`).
  DoD: unit-тест правил валидации — таблица валидных/невалидных URL из spec.md, включая отказ для не-Яндекс доменов и нерелевантных страниц карт (маршруты/метро).
- [ ] **4.2** `CompanyController@store` — создание либо возврат существующей записи по `normalized_url`/`yandex_id` (дедуп US-2), без автозапуска парсинга.
  DoD: Feature-тест: первый `POST /api/companies` создаёт запись (`201`); повторный запрос с той же (нормализованной) ссылкой возвращает существующую запись без дубля в БД (`200`, `count(companies) == 1`); невалидная ссылка → `422`.
- [ ] **4.3** `CompanyController@index` и `CompanyController@show` — список организаций и детали одной (метрики + статус, plan.md §1.4).
  DoD: Feature-тесты: `GET /api/companies` возвращает пагинированный/полный список текущих компаний авторизованного пользователя; `GET /api/companies/{id}` возвращает поля `rating`, `ratings_count`, `reviews_count`, `parse_status`, `last_parsed_at`; `404` для несуществующего id.
- [ ] **4.4** `CompanyController@parse` — создание `parsing_logs` (`pending`) + `ParseYandexCompanyJob::dispatch`, с проверкой edge case §4.8 (уже есть активный `pending`/`processing`).
  DoD: Feature-тест: первый `POST /api/companies/{id}/parse` → `202`, Job поставлен в очередь (`Queue::fake()->assertPushed`); повторный вызов пока статус `processing` → `409`, второй Job не добавляется.
- [ ] **4.5** `ReviewController@index` — `GET /api/companies/{id}/reviews?page=X`, `paginate(50)`, сортировка по `review_created_at desc` (US-4).
  DoD: Feature-тест: компания с 120 отзывами → `page=1` отдаёт 50 записей + метаданные пагинации (`current_page`, `last_page`, `total=120`); `page=3` отдаёт оставшиеся 20; пустой список (0 отзывов) → `200` с пустым `data`, не `404`/`500` (edge case §5 spec.md).
- [ ] **4.6** Роутинг: зарегистрировать все эндпоинты из plan.md §1.4 в `routes/api.php`/`routes/web.php` под `auth:sanctum`.
  DoD: `php artisan route:list` показывает все роуты с корректным middleware; полный Feature-тест-сьют Phase 2–4 зелёный (`php artisan test`).
- [ ] **4.7** Авторизационная проверка принадлежности (edge case §4.9 spec.md, если применимо к модели данных) — либо явно задокументировать в коде, что в MVP организации общие для всех авторизованных пользователей (в соответствии с допущением plan.md §5).
  DoD: комментарий/README-заметка зафиксирована; либо (если вводится `user_id` на `companies`) — Policy + тест `403` на чужую организацию.

---

## Phase 5: Frontend Auth & Router (`./frontend`)

- [ ] **5.1** Инициализировать Vue 3 + Vite проект в `./frontend` (`npm create vite@latest`, Vue Router, Pinia установлены).
  DoD: `npm run dev` поднимает пустое приложение без ошибок в консоли.
- [ ] **5.2** Настроить Axios-инстанс (`src/api/http.js`) с `baseURL` backend, `withCredentials: true`, интерцептором на `401` (редирект на `/login`) и инициализацией CSRF-cookie перед логином.
  DoD: ручная проверка через DevTools Network — запрос к `/sanctum/csrf-cookie` отправляется до `POST /login`, cookie `XSRF-TOKEN`/`laravel_session` видны в браузере после логина.
- [ ] **5.3** `useAuthStore` (Pinia): состояние `user`/`isAuthenticated`, действия `login`, `logout`, `fetchUser`.
  DoD: unit-тест стора (Vitest) с замоканным Axios — `login` при успехе устанавливает `user`, при `422` пробрасывает ошибку без установки `user`.
- [ ] **5.4** `LoginForm.vue` — форма email/пароль, вызов `useAuthStore.login`, отображение ошибок.
  DoD: ручная проверка в браузере — вход с сид-пользователем из Phase 2.1 успешно логинит и редиректит на главную; неверный пароль показывает сообщение об ошибке.
- [ ] **5.5** Настроить Vue Router: `/login` (гостевой), `/` и `/companies/:id` (защищённые через `beforeEach`-guard, проверяющий `useAuthStore.isAuthenticated`, с предварительным `fetchUser()` при старте приложения для восстановления сессии по cookie).
  DoD: прямой заход на `/` без сессии редиректит на `/login`; после логина заход на `/login` редиректит на `/`; обновление страницы (`F5`) на защищённом роуте с активной cookie-сессией не разлогинивает.
- [ ] **5.6** Кнопка/действие логаута, вызывающее `useAuthStore.logout` и редирект на `/login`.
  DoD: ручная проверка — после логаута повторный заход на `/` без релогина невозможен (редирект на `/login`), cookie сессии инвалидирована на backend.

---

## Phase 6: Frontend Settings & Parser View (`./frontend`)

- [ ] **6.1** `useCompanyStore` (Pinia): состояние `companies[]`, `currentCompany`, `reviews[]`, `pagination`; действия `addCompany`, `fetchCompany`, `fetchCompanies`, `startParsing`, `fetchReviews`.
  DoD: unit-тесты (Vitest, замоканный Axios) на каждое действие — успешный и `422`/`409`-сценарии для `addCompany`/`startParsing`.
- [ ] **6.2** `CompanyInput.vue` — поле ввода ссылки с клиентской regex-подсказкой (не заменяющей backend-валидацию) + вызов `addCompany`.
  DoD: ручная проверка — ввод валидной ссылки на организацию создаёт запись в списке; ввод произвольного текста показывает клиентскую подсказку без похода на сервер; сохранённая на backend `422`-ошибка (например, ссылка на немапс-домен, обойдя клиентскую проверку через paste) отображается пользователю.
- [ ] **6.3** `ParsingStatusBadge.vue` — визуализация `pending`/`processing`/`completed`/`failed` с сообщением ошибки для `failed`.
  DoD: Storybook-less ручная проверка — компонент рендерит все 4 состояния с разными визуальными стилями (проверить смену цвета/иконки); при `failed` показан `errorMessage`.
- [ ] **6.4** Реализовать polling статуса в `useCompanyStore` (периодический `fetchCompany`, останавливается при `completed`/`failed`, очищается при размонтировании страницы деталей).
  DoD: ручная проверка — запуск парсинга (`startParsing`) показывает `pending` → `processing` → `completed` без ручного обновления страницы; после ухода со страницы деталей polling прекращается (проверка через DevTools Network — запросы не продолжаются в фоне).
- [ ] **6.5** `CompanyMetrics.vue` — отображение `rating`, `ratings_count`, `reviews_count`, `last_parsed_at`.
  DoD: ручная проверка на компании с реально собранными тестовыми данными (сид/фикстуры) — значения совпадают с тем, что отдаёт `GET /api/companies/{id}`.
- [ ] **6.6** `ReviewsList.vue` — список отзывов с пустым состоянием «отзывы ещё не собраны» и отдельным отображением «оценка без комментария» (edge case §4.7 spec.md).
  DoD: ручная проверка на трёх сценариях — организация без запуска парсинга (пустое состояние), организация с отзывами разных типов (с текстом / без текста), визуальная проверка обоих случаев.
- [ ] **6.7** `Pagination.vue` — универсальный пагинатор, интеграция с `fetchReviews(id, page)` и метаданными от backend (по 50 на страницу, US-4).
  DoD: ручная проверка на организации с >50 отзывов (тестовые фикстуры) — переключение страниц подгружает следующие 50 без полной перезагрузки страницы, кнопка Next неактивна на последней странице.
- [ ] **6.8** Страница `CompanyDetailPage.vue`, собирающая `CompanyMetrics` + `ParsingStatusBadge` + кнопку запуска парсинга + `ReviewsList` + `Pagination` (plan.md §2.2).
  DoD: End-to-end ручной прогон полного пользовательского пути: логин → добавление ссылки → запуск парсинга → наблюдение статуса → просмотр отзывов с пагинацией — без ошибок в консоли браузера.

---

## Phase 7: Final README.md & Deployment Guide (без Docker)

- [ ] **7.1** Корневой `README.md`: описание проекта, ссылка на `.sdd/spec.md` и `.sdd/plan.md`, структура монорепозитория (`./backend`, `./frontend`, `./.sdd`).
  DoD: файл существует в корне, содержит рабочие относительные ссылки на `.sdd/*.md`.
- [ ] **7.2** Раздел «Backend setup»: `composer install`, копирование `.env.example` → `.env`, `php artisan key:generate`, настройка MySQL-подключения (`127.0.0.1:3306`, `DailyGrowTestLaravel`, `mysql`/`mysql`), `php artisan migrate --seed`.
  DoD: свежий клон репозитория, выполнение шагов по инструкции с нуля приводит к рабочей БД с сид-пользователем (проверка `php artisan tinker` → `User::count() === 1`).
- [ ] **7.3** Раздел «Queue worker»: `php artisan queue:work --queue=parsing` — отдельная команда, обязательная для обработки `ParseYandexCompanyJob` (без неё статус останется `pending` вечно).
  DoD: инструкция явно предупреждает, что без запущенного воркера парсинг не продвинется дальше `pending`; проверено запуском воркера в отдельном терминале и наблюдением перехода статуса.
- [ ] **7.4** Раздел «Frontend setup»: `npm install`, `npm run dev` (Vite dev-сервер), настройка `.env`/переменной с backend `baseURL` для Axios.
  DoD: свежий клон, выполнение шагов приводит к рабочему фронтенду на `localhost:5173` (или актуальный порт Vite), успешно обращающемуся к backend на `localhost:8000`.
- [ ] **7.5** Раздел «Запуск всего стека локально (без Docker)»: последовательность из 3 терминалов — `php artisan serve` (backend, порт 8000), `php artisan queue:work --queue=parsing` (воркер), `npm run dev` (frontend, порт 5173) — с указанием CORS/`SANCTUM_STATEFUL_DOMAINS` под связку портов.
  DoD: чистый прогон всех трёх команд по инструкции на новой машине/новом клоне → полный пользовательский путь (логин → добавление → парсинг → просмотр отзывов) работает без ручных дополнительных правок конфигурации.
- [ ] **7.6** Раздел «Тестирование»: команды `php artisan test` (backend) и `npm run test`/`vitest` (frontend), краткое описание покрытия (Phase 1–6).
  DoD: обе команды выполняются успешно на чистом клоне после выполнения setup-шагов 7.2/7.4.
- [ ] **7.7** Раздел «Известные ограничения» (перенос из plan.md §3.4 и §5): отсутствие полной истории изменений отзывов, общий (не пользовательский) скоуп организаций в MVP, ручной/не по расписанию запуск парсинга.
  DoD: раздел присутствует в README, соответствует формулировкам допущений plan.md.
