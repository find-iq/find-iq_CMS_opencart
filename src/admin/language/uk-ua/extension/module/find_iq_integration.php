<?php

// Heading
$_['heading_name'] = 'Find-IQ Integration';

// Error
$_['error_warning'] = 'Файл логу <strong>%s</strong> завеликий (%s) і не відображається нижче. Очистіть або заархівуйте його.';

$_['heading_title'] = '
    <span style="background: #fff; padding: 4px 0 4px 10px;border-radius: 4px;">
    <span style="color:#ff0000;text-shadow: none;font-weight: 500;">Find-IQ</span>
    <span style="color: #fff;background: #141414;padding: 4px 8px;border-radius: 4px;">Integration</span>
    ';

// Text
$_['text_extension'] = 'Розширення';
$_['text_success'] = 'Налаштування успішно змінено!';
$_['text_edit'] = 'Налаштування модуля';
$_['text_list'] = 'Лог';
$_['entry_status'] = 'Статус';
$_['text_token'] = 'Токен';
$_['text_token_placeholder'] = 'Введіть секретний токен сайту ';
$_['text_full_reindex_timeout'] = 'Погодинний інтервал оновлення, якщо згідно тарифу він вищий то частіше оновлення не буде мати ефекту, дане значення впливає на оновлення товарів у системі FindIQ, а не безпосередньо у пошуковому сервісі';
$_['text_full_reindex_timeout_placeholder'] = 'Рекомендовані значення 8-48 для більшості магазинів';
$_['text_fast_reindex_timeout'] = 'Погодинний інтервал швидкого оновлення (ціни, акції, наявність), якщо згідно тарифу він вищий то частіше оновлення не буде мати ефекту, дане значення впливає на оновлення товарів у системі FindIQ, а не безпосередньо у пошуковому сервісі';
$_['text_fast_reindex_timeout_placeholder'] = 'Рекомендовані значення 1-8 для більшості магазинів';
$_['text_resize'] = 'Розмір ресайзу';
$_['text_resize_pattern'] = '%spx x %spx';
$_['text_image_processor'] = 'Обробка зображень';
$_['text_image_processor_gd'] = 'Вбудована (GD) — рекомендовано';
$_['text_image_processor_opencart'] = 'OpenCart (може потребувати Imagick)';
$_['text_image_processor_help'] = 'Якщо синхронізація падає з помилкою «Class Imagick not found» — використовуйте вбудовану GD обробку.';
$_['text_docs'] =  'Інформація та документація';

// Contacts block (Documentation tab)
$_['text_contacts_heading']   = 'Контакти та ресурси';
$_['text_contacts_site']      = 'Сайт';
$_['text_contacts_admin']     = 'Адмін панель';
$_['text_contacts_api_docs']  = 'API документація';
$_['text_contacts_write']     = 'Написати нам';
$_['text_contacts_telegram']  = 'Telegram канал';
$_['text_contacts_youtube']   = 'YouTube канал';
$_['text_contacts_github']    = 'GitHub';

// Webhook quick-links block (Documentation tab)
$_['text_webhook_heading']  = 'Швидкі URL для вебхуків';
$_['text_webhook_help']     = 'Готові до копіювання URL з поточним секретним токеном.';
$_['text_webhook_start_full'] = 'Старт — повна заливка (нові + змінені + переіндекс)';
$_['text_webhook_start_fast'] = 'Старт — швидке оновлення (тільки ціни та наявність)';
$_['text_webhook_status']     = 'Статус';
$_['text_webhook_stop']       = 'Стоп';
$_['text_webhook_frontend'] = 'Оновити віджет (frontend)';
$_['text_webhook_copy']     = 'Скопіювати';
$_['text_webhook_no_token'] = 'Задайте секретний токен у налаштуваннях і збережіть — тут зʼявляться готові URL вебхуків.';
$_['text_version'] = 'Версія: %s';
$_['text_src'] = '<a href="%s">Вихідний код</a>';
$_['text_new_version_available'] = 'Доступна нова версія модуля: <b>%s</b>. Поточна версія: <b>%s</b>. <a href="%s"><b>Завантажити оновлення</b></a>';
$_['text_version_is_actual'] = 'У вас встановлена актуальна версія модуля';
$_['text_current_version_is_corrupt'] = 'Ваша версія імовірно має відкликану версію, перевірте чи у вас версія з <a href="%s"><b>нашого репозиторію</b></a>, є підозра, що це не так';
$_['text_error_version_check'] = 'Помилка перевірки версії: ';
$_['text_error_version_check_current'] = 'Не вдалося визначити поточну версію модуля;';
$_['text_error_version_check_remote'] = 'Не вдалося перевірити нову версію через інтернет; ';



// Error
$_['error_permission'] = 'Немає прав для управління даним модулем!';
