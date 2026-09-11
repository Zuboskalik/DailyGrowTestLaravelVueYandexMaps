/**
 * The backend (Laravel) currently returns its validation/error messages in
 * English. Rather than showing that mixed-language text to Russian-speaking
 * users, map the known, fixed messages we control to Russian and fall back
 * to a generic message for anything unrecognized.
 */
const KNOWN_MESSAGES = {
  'These credentials do not match our records.': 'Неверный email или пароль.',
  'The url field is required.': 'Укажите ссылку на организацию.',
  'The url must be a link to a Yandex Maps organization page.':
    'Ссылка должна вести на страницу организации в Яндекс.Картах.',
  'The url field must not be greater than 2048 characters.':
    'Ссылка слишком длинная.',
  'A parsing run is already in progress for this company.':
    'Сбор отзывов для этой организации уже выполняется.',
}

export function translateApiMessage(message, fallback = 'Что-то пошло не так. Попробуйте ещё раз.') {
  if (!message) return fallback

  return KNOWN_MESSAGES[message.trim()] ?? fallback
}
