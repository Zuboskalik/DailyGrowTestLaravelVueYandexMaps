# Технический план: Сервис сбора отзывов с Яндекс.Карт

Основано на [`spec.md`](./spec.md). Описывает архитектуру `./backend` (Laravel) и `./frontend` (Vue 3), без реализации — только структура, контракты и обоснования решений.

---

## 1. Backend Architecture (`./backend`)

### 1.1. Database Schema

#### `users`
Стандартная таблица Laravel (`id`, `name`, `email`, `password`, `remember_token`, timestamps). Заполняется `UserSeeder` (US-1). Дополнительных полей не требуется.

#### `companies`
| Поле | Тип | Описание |
|---|---|---|
| `id` | `bigint unsigned, PK` | |
| `url` | `string(2048)` | Оригинальная ссылка, введённая пользователем |
| `normalized_url` | `string(2048), nullable, index` | Каноническая ссылка после резолва редиректов — используется для дедупликации при добавлении (US-2) |
| `yandex_id` | `string(64), nullable, unique` | `external_org_id`, извлечённый из URL или после резолва |
| `name` | `string, nullable` | Название организации (заполняется парсером) |
| `rating` | `decimal(3,2), nullable` | Средний рейтинг, заявленный источником |
| `reviews_count` | `unsigned int, default 0` | Фактическое число отзывов, собранных системой (агрегат из `reviews`) |
| `ratings_count` | `unsigned int, nullable` | Число оценок, заявленное источником (используется в правиле 3.3 spec.md) |
| `parse_status` | `enum('idle','pending','processing','completed','failed'), default 'idle'` | Текущий статус последнего/текущего запуска (US-3) |
| `last_parsed_at` | `timestamp, nullable` | Время последнего успешного завершения |
| `last_error` | `string, nullable` | Краткое сообщение последней ошибки (edge cases §4) |
| `created_at`, `updated_at` | `timestamp` | |

Индексы: `unique(yandex_id)` — где `NOT NULL` (частичная уникальность реализуется на уровне приложения/условного индекса, т.к. допускаются организации без резолва `yandex_id` на момент создания), `index(normalized_url)`.

#### `reviews`
| Поле | Тип | Описание |
|---|---|---|
| `id` | `bigint unsigned, PK` | |
| `company_id` | `bigint unsigned, FK -> companies.id, cascadeOnDelete` | |
| `external_id` | `string(128)` | Идентификатор отзыва на стороне Яндекса (US: идемпотентность 3.2) |
| `author_name` | `string, nullable` | |
| `rating` | `unsigned tinyint, nullable` | 1–5, `nullable` для случая «оценка без текста и без явной оценки» (крайний случай) |
| `text` | `text, nullable` | `null`/пусто → флаг «отзыв без текста» (edge case §4.7) вычисляется на лету (`text === null`), отдельное поле не требуется |
| `review_created_at` | `timestamp, nullable` | Дата отзыва по данным источника (может быть неточной — Яндекс часто отдаёт относительную дату типа «2 месяца назад»; хранится как лучшее приближение) |
| `created_at`, `updated_at` | `timestamp` | Технические метки системы (когда запись впервые сохранена / последний раз обновлена upsert'ом) |

Индексы: `unique(company_id, external_id)` — ключевое ограничение идемпотентности (spec.md §3.2), `index(company_id, review_created_at)` для сортировки при пагинации.

#### `parsing_logs`
Журнал запусков парсинга — хранит историю (US-3: «повторный запуск создаёт новую запись запуска, история сохраняется»).

| Поле | Тип | Описание |
|---|---|---|
| `id` | `bigint unsigned, PK` | |
| `company_id` | `bigint unsigned, FK -> companies.id, cascadeOnDelete` | |
| `status` | `enum('pending','processing','completed','failed')` | |
| `reviews_collected` | `unsigned int, default 0` | Сколько отзывов создано/обновлено за этот запуск |
| `error_type` | `enum('validation','not_found','banned','timeout','structure_changed','unknown'), nullable` | Классификация ошибки — различает edge cases §4 (баны, таймауты, изменение разметки) |
| `error_message` | `text, nullable` | |
| `started_at` | `timestamp, nullable` | |
| `finished_at` | `timestamp, nullable` | |
| `created_at`, `updated_at` | `timestamp` | |

Индекс: `index(company_id, created_at)` — для отображения истории и для проверки «уже выполняется» (US-3, edge case §4.8: ищем запись `company_id` со статусом `pending`/`processing`).

---

### 1.2. Parsing Strategy

**Выбор: перехват скрытых HTTP JSON-запросов (внутренний AJAX/GraphQL Яндекс.Карт), а не headless-браузер, как основной подход.**

| Критерий | JSON/AJAX перехват | Headless-браузер (Playwright/Puppeteer) |
|---|---|---|
| Скорость / нагрузка на CPU | Высокая — простой HTTP-запрос + JSON-парсинг | Низкая — рендеринг полной страницы, JS-движок, ~5–10x дороже по CPU/RAM на воркер |
| Устойчивость к антибот-системам | Требует эмуляции корректных заголовков/сессионных токенов, но не исполняет JS-детекцию headless-браузеров (нет WebDriver-флагов, canvas fingerprint и т.п.) | Более «похож на пользователя», но легче детектируется по fingerprint headless-режима, требует stealth-плагинов |
| Стабильность к изменению вёрстки | Зависит от стабильности внутреннего API-контракта (обычно куда стабильнее HTML/CSS-классов) | Зависит от CSS-селекторов/DOM-структуры — чаще ломается при редизайне |
| Масштабируемость (§3 «50 филиалов») | Легко горизонтально масштабируется — много параллельных лёгких HTTP-воркеров | Каждый инстанс браузера — отдельный процесс, дорого масштабировать на очереди |
| Сложность поддержки | Требует реверс-инжиниринга формата ответа при его смене | Требует обновления селекторов при смене вёрстки |
| Инфраструктура (без Docker, по условиям проекта) | Не требует установки Chromium/зависимостей уровня ОС | Требует Chromium + системные зависимости — усложняет деплой без контейнеризации |

**Итог:** для проекта без Docker и с требованием масштабирования на десятки организаций приоритет — легковесный подход через внутренние JSON-эндпоинты страницы организации (запросы, которые сама страница Яндекс.Карт делает для получения отзывов/карточки — постраничная выдача отзывов, агрегаты рейтинга). Headless-браузер выносится в **резервную стратегию** (fallback), включаемую только если:
- основной JSON-эндпоинт вернул структуру, не проходящую валидацию схемы ответа (потенциальный признак изменения контракта, до эскалации в `ParsingStructureChangedException`);
- либо явно включён флагом конфигурации на время диагностики.

Это разделение закладывается через интерфейс `ReviewSourceStrategy` с двумя реализациями (`HttpJsonStrategy` — основная, `HeadlessBrowserStrategy` — резервная/на будущее), инжектируемыми в `YandexMapParserService`.

**Стратегия обхода защиты:**
- **User-Agent rotation** — пул реалистичных UA (актуальные версии Chrome/Firefox на десктопе), случайный выбор на каждый запуск задания (не на каждый HTTP-запрос внутри одного задания — сессия должна быть консистентной).
- **Throttling / delays** — искусственная задержка между постраничными запросами отзывов одной организации (случайный интервал в диапазоне, например 1–3 секунды), чтобы не создавать паттерн бота с фиксированным интервалом.
- **Rate limiting на уровне очереди** — ограничение количества одновременно исполняемых `ParseYandexCompanyJob` (через выделенную очередь `parsing` с ограниченным числом воркеров/concurrency limit Laravel), чтобы не создавать всплеск запросов к Яндексу при массовом запуске (актуально при масштабировании на 50 филиалов — см. §3.3).
- **Ретраи с backoff** — при `403`/капче/сетевых сбоях — не мгновенный повтор, а экспоненциальный backoff через встроенный механизм Job (`$backoff`), ограниченное число попыток (`$tries`), после чего — `failed` с классификацией `banned`/`timeout` в `parsing_logs.error_type`.
- **Прокси** — архитектурно закладывается точка расширения (конфигурация пула прокси в `HttpJsonStrategy`), но не обязательна для MVP; вынесена в раздел нефункциональных требований (§3.1).

---

### 1.3. Services & Jobs

#### `YandexMapParserService` (чистый сервис, без побочных эффектов на очередь/HTTP-слой контроллеров)
Ответственность: получить данные организации и отзывы из источника, вернуть структурированный DTO. Не знает о существовании очередей, HTTP-контроллеров или Eloquent-моделей запросов — принимает ссылку/идентификатор, отдаёт данные.

Публичный контракт (сигнатуры, без реализации):
- `resolveOrganization(string $url): OrganizationSnapshot` — резолвит редирект (если короткая ссылка), извлекает `yandex_id`, `name`, `rating`, `ratings_count`.
- `fetchReviews(string $yandexId, int $limit = 600): Generator<ReviewSnapshot>` — постранично тянет отзывы через `ReviewSourceStrategy`, отдаёт как ленивую последовательность (позволяет upsert'ить по мере получения — edge case §4.10 «частичный сбор»), останавливается при достижении `$limit` либо при исчерпании источника.
- Внутренняя проверка правила 3.3 spec.md: если `ratings_count > 0`, а `fetchReviews` не отдал ни одного элемента — выбрасывает `ParsingStructureChangedException`.
- Прочие исключения домена: `OrganizationNotFoundException`, `SourceBannedException`, `SourceTimeoutException` — все наследуют общий `ParsingException`, что даёт `ParseYandexCompanyJob` единую точку `catch` с последующей классификацией по типу.

#### `ParseYandexCompanyJob` (`ShouldQueue`)
Ответственность: оркестрация одного запуска парсинга конкретной организации; сам не парсит — делегирует `YandexMapParserService`.

Обязанности:
1. При старте (`handle()`): пометить связанную запись `parsing_logs` статусом `processing` (+ `started_at`), обновить `companies.parse_status = 'processing'`.
2. Вызвать `resolveOrganization`, обновить поля `companies` (`name`, `rating`, `ratings_count`, `yandex_id`, `normalized_url`).
3. Итерировать `fetchReviews(...)`, на каждый элемент — `Review::updateOrCreate(['company_id' => ..., 'external_id' => ...], [...])` (реализация идемпотентности 3.2 на уровне Job/сервиса-репозитория).
4. По завершении итерации без исключений: `parsing_logs.status = 'completed'`, `finished_at`, `reviews_collected`; `companies.parse_status = 'completed'`, `last_parsed_at = now()`, `companies.reviews_count` пересчитывается агрегатом.
5. `catch (ParsingException $e)`: классифицировать в `parsing_logs.error_type`/`error_message`, `parsing_logs.status = 'failed'`, `companies.parse_status = 'failed'`, `companies.last_error`.
6. Настройки очереди: выделенная очередь `parsing`; `$tries = 3`; `backoff()` — экспоненциальный (например `[30, 120, 300]` секунд); `failed()` — гарантированная фиксация `failed` в БД даже если само исключение Laravel-инфраструктуры (не доменное) прервало Job после исчерпания попыток.

#### `CompanyController`
- `store(StoreCompanyRequest $request)` — валидация ссылки (regex, US-2), поиск дубля по `normalized_url`/`yandex_id`, создание либо возврат существующей записи. **Не** диспетчеризует Job автоматически.
- `show(Company $company)` — данные организации (метрики, статус, `last_parsed_at`).
- `parse(Company $company)` (отдельный экшен/роут) — проверка edge case §4.8 (нет активного `pending`/`processing` в `parsing_logs`), создание записи `parsing_logs` со статусом `pending`, `ParseYandexCompanyJob::dispatch($company)`.

#### `ReviewController`
- `index(Company $company, Request $request)` — `Review::where('company_id', $company->id)->orderByDesc('review_created_at')->paginate(50)`.

---

### 1.4. API Routes

Аутентификация — Laravel Sanctum SPA (cookie-based, `stateful` middleware), маршруты логина/логаута — стандартные `web`-роуты, остальное API — `auth:sanctum`.

| Метод | Путь | Контроллер/Экшен | Auth | Описание |
|---|---|---|---|---|
| `GET` | `/sanctum/csrf-cookie` | (встроенный Sanctum) | — | Инициализация CSRF-cookie перед логином (стандартный SPA-флоу) |
| `POST` | `/login` | (built-in / кастомный `AuthenticatedSessionController@store`) | — | Логин сид-пользователя (US-1) |
| `POST` | `/logout` | `AuthenticatedSessionController@destroy` | `auth:sanctum` | Завершение сессии |
| `GET` | `/api/user` | (closure/`Auth::user()`) | `auth:sanctum` | Текущий авторизованный пользователь |
| `POST` | `/api/companies` | `CompanyController@store` | `auth:sanctum` | Добавление организации (US-2) |
| `GET` | `/api/companies` | `CompanyController@index` | `auth:sanctum` | Список добавленных организаций (для главного экрана) |
| `GET` | `/api/companies/{id}` | `CompanyController@show` | `auth:sanctum` | Метрики + статус организации (US-3, для polling) |
| `POST` | `/api/companies/{id}/parse` | `CompanyController@parse` | `auth:sanctum` | Запуск асинхронного парсинга (US-3) |
| `GET` | `/api/companies/{id}/reviews?page=X` | `ReviewController@index` | `auth:sanctum` | Список отзывов, серверная пагинация по 50 (US-4) |

Коды ответов согласно spec.md §4: `422` (валидация ссылки), `401` (неавторизован), `409` (повторный запуск во время активного парсинга), `404` (организация не найдена/не принадлежит пользователю).

---

## 2. Frontend Architecture (`./frontend`)

### 2.1. Инфраструктура

- **Vue 3 + Vite** — стандартный `create-vue` скаффолд, Composition API (`<script setup>`) во всех компонентах.
- **Axios** — единый инстанс (`src/api/http.js`) с `baseURL` backend, `withCredentials: true` (обязательно для Sanctum SPA cookie-сессий), плюс `axios.get('/sanctum/csrf-cookie')` перед логином и интерцептор, обрабатывающий `401` (редирект на логин) и `419` (истёкший CSRF — повторная инициализация cookie).
- **Vue Router** — маршруты: `/login` (гостевой), `/` (список организаций, защищённый), `/companies/:id` (детали + отзывы, защищённый); `beforeEach`-guard проверяет `useAuthStore`.
- **Pinia**:
  - `useAuthStore` — состояние `user`, `isAuthenticated`; действия `login(credentials)`, `logout()`, `fetchUser()` (вызывается при старте приложения для восстановления сессии по cookie).
  - `useCompanyStore` — состояние `companies[]`, `currentCompany`, `reviews[]`, `pagination` (`currentPage`, `lastPage`, `total`); действия `addCompany(url)`, `fetchCompany(id)`, `startParsing(id)`, `fetchReviews(id, page)`; здесь же — логика polling статуса (`setInterval`/повторный `fetchCompany` каждые N секунд, пока `parse_status` в `pending`/`processing`, с остановкой по `completed`/`failed` или размонтированию компонента).

### 2.2. Компоненты

| Компонент | Ответственность |
|---|---|
| `LoginForm.vue` | Форма email/пароль, вызывает `useAuthStore.login`, отображает ошибки валидации/авторизации (US-1) |
| `CompanyInput.vue` | Поле ввода ссылки + кнопка «Добавить», клиентская regex-проверка формата для мгновенной подсказки (дублирует backend-валидацию, не заменяет её), отображение серверных ошибок `422` (US-2) |
| `ParsingStatusBadge.vue` | Цветной индикатор статуса (`pending`/`processing`/`completed`/`failed`) с текстом; принимает `status` и опционально `errorMessage` как props; переиспользуется в списке организаций и на странице деталей (US-3) |
| `CompanyMetrics.vue` | Блок с `rating`, `ratings_count`, `reviews_count`, `last_parsed_at`; принимает объект `company` как prop (US-4, шапка списка отзывов) |
| `ReviewsList.vue` | Список карточек отзывов (`author_name`, `rating`, `text`/пустое состояние «оценка без комментария», `review_created_at`); пустое состояние всего списка — «отзывы ещё не собраны» (US-4, edge case §4.5) |
| `Pagination.vue` | Универсальный пагинатор (текущая страница, общее число страниц, кнопки Prev/Next); эмитит `update:page`, используется `ReviewsList`-контейнером |

Композиция: страница `CompanyDetailPage.vue` (view, не в списке выше — уровень роутинга) собирает `CompanyMetrics` + `ParsingStatusBadge` + кнопку запуска парсинга + `ReviewsList` + `Pagination`, используя `useCompanyStore`.

---

## 3. Nonfunctional Requirements & README (черновик разделов)

### 3.1. Анти-бан

- Многоуровневая защита от блокировки источника:
  1. **User-Agent rotation** — пул актуальных десктопных UA, фиксируется на весь запуск задания.
  2. **Throttling** — случайная задержка 1–3 сек между постраничными запросами отзывов; отсутствие параллельных запросов к одной и той же организации.
  3. **Ограничение конкурентности очереди** — не более N одновременных `ParseYandexCompanyJob` (например, через `WithoutOverlapping` middleware Job на уровне организации + общий лимит воркеров очереди `parsing`), чтобы суммарная нагрузка на Яндекс не росла линейно с числом организаций.
  4. **Классификация банов** — при HTTP `403`/редиректе на captcha-страницу — немедленная остановка (без бесполезных ретраев «в лоб»), классификация `error_type = 'banned'` в `parsing_logs`, экспоненциальный backoff перед следующей попыткой (либо ручной перезапуск пользователем).
  5. **Точка расширения — прокси-пул**: конфигурационная опция ротации исходящего IP (не входит в MVP, но интерфейс `HttpJsonStrategy` проектируется с учётом внедрения прокси-провайдера без изменения вызывающего кода).

### 3.2. Изменение разметки/контракта источника

- Реализуется через `ParsingStructureChangedException` (spec.md §3.3): при `ratings_count > 0` и `0` собранных отзывов — это не пустой результат, а сигнал деградации парсера.
- Такие сбои **явно отделены** в `parsing_logs.error_type = 'structure_changed'` от обычных `failed` (баны/таймауты), что позволяет:
  - настроить отдельный алерт (например, лог-канал/уведомление разработчику) именно на этот тип ошибки — он требует вмешательства кода, а не повторной попытки;
  - не «зашумлять» обычные ретраи очереди — на структурные изменения повторный автоматический retry того же Job бессмысленен (ошибка не пройдёт сама), поэтому `ParsingStructureChangedException` может быть помечен как non-retryable (`$tries = 1` для этого конкретного класса ошибки, либо явный `$this->fail($e)` без дальнейших попыток).
- Версионирование стратегии парсинга (`ReviewSourceStrategy`) закладывает возможность быстро подключить `HeadlessBrowserStrategy` как временный fallback на период, пока `HttpJsonStrategy` не обновлена под новый контракт источника.

### 3.3. Масштабирование на 50 филиалов

- **Очереди**: выделенная очередь `parsing` с несколькими воркерами (`php artisan queue:work --queue=parsing`), горизонтально масштабируемая запуском дополнительных процессов воркеров (без Docker — через systemd/Supervisor-юниты на хосте).
- **Rate limiting**: общий лимит одновременных заданий парсинга (независимо от числа организаций) через `Illuminate\Queue\Middleware\RateLimited` или `WithoutOverlapping` — не даёт системе создать всплеск из 50 параллельных запросов к Яндексу одновременно; задания сериализуются/ограничиваются пулом (например, не более 3–5 одновременных парсингов).
- **БД**: индексы `unique(company_id, external_id)` на `reviews` и `index(company_id, review_created_at)` держат upsert и пагинацию быстрыми при росте общего объёма отзывов (50 организаций × ~600 = до ~30 000 записей — тривиальный объём для MySQL без дополнительного партиционирования).
- **Плановые перезапуски**: при 50 филиалах разумно ввести периодический перезапуск парсинга по расписанию (Laravel Scheduler, `php artisan schedule:run` — cron), а не только по ручному триггеру пользователя, но это отдельная story сверх текущего скоупа spec.md (упомянуть как будущее расширение).
- **Изоляция сбоев**: сбой парсинга одной организации (`failed`) не влияет на остальные — каждая обрабатывается собственным Job/записью `parsing_logs`.

### 3.4. История изменений

- `parsing_logs` уже фиксирует историю каждого запуска (US-3: «история запусков сохраняется») — этого достаточно для аудита «когда и с каким результатом запускался парсинг».
- Для истории изменений **самих отзывов** (например, если текст/оценка отзыва меняется между запусками) текущая схема хранит только последнее состояние (upsert перезатирает поля). Полная история версий отзыва — вне MVP-скоупа spec.md; при необходимости может быть добавлена отдельная таблица `review_history` (аудит-лог изменений полей) как будущее расширение, не блокирующее текущий план.
- Дата создания/обновления записи (`created_at`/`updated_at` в `reviews`) позволяет как минимум ответить на вопрос «когда система впервые увидела этот отзыв» и «когда последний раз подтверждала его актуальность».

---

## 4. Соответствие плану — трассировка к user stories

| Story (spec.md) | Реализующие элементы плана |
|---|---|
| US-1 Логин | Sanctum SPA routes, `useAuthStore`, `LoginForm.vue` |
| US-2 Добавление ссылки | `StoreCompanyRequest` (regex), `CompanyController@store`, `companies.normalized_url`/`yandex_id` unique-логика, `CompanyInput.vue` |
| US-3 Асинхронный парсинг + статус | `parsing_logs`, `ParseYandexCompanyJob`, `CompanyController@parse`, `ParsingStatusBadge.vue`, polling в `useCompanyStore` |
| US-4 Список отзывов с пагинацией | `ReviewController@index` (`paginate(50)`), `ReviewsList.vue`, `Pagination.vue`, `CompanyMetrics.vue` |
| §3.2 Идемпотентность | `unique(company_id, external_id)`, `updateOrCreate` в Job |
| §3.3 Изменение разметки | `YandexMapParserService`, `ParsingStructureChangedException`, `parsing_logs.error_type = 'structure_changed'` |
| §4 Edge cases | Коды `422/401/409/404`, классификация `error_type` (`banned`/`timeout`/`not_found`/`structure_changed`), `WithoutOverlapping` для §4.8, ленивый upsert по мере сбора для §4.10 |

---

## 5. Ограничения и допущения

- **Многопользовательская изоляция данных организаций не входит в MVP.** В схеме `companies` нет `user_id` — любой авторизованный пользователь видит и может запускать парсинг для всех добавленных организаций (общий, а не персональный список). Для реального многопользовательского продукта это первое, что потребует добавления `user_id` + `Policy`.
- **Реальный механизм получения данных со страниц Яндекс.Карт** (точный контракт внутренних JSON-эндпоинтов, антибот-обход конкретно под их защиту) — техническая деталь реализации, уточняемая на этапе реверс-инжиниринга, не описывается на уровне функциональной спецификации.
- **Ретраи очереди** (количество попыток, backoff) конфигурируются на уровне Laravel Queue/Job (`$tries`, `backoff()`), точные числовые значения — предмет технического проектирования, не бизнес-требование.
- **Нет полной истории изменений отзывов** — только история запусков парсинга (`parsing_logs`). См. §3.4.
- **Запуск парсинга только вручную** через `POST /api/companies/{id}/parse` — плановый перезапуск по расписанию не входит в MVP-скоуп (см. §3.3 про Laravel Scheduler как возможное расширение).
