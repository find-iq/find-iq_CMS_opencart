<?php

// Heading
$_['heading_name'] = 'Find-IQ Integration';

// Error
$_['error_warning'] = 'Файл лога <strong>%s</strong> слишком большой (%s) и не отображается ниже. Очистите или заархивируйте его.';

$_['heading_title'] = '
    <span style="background: #fff; padding: 4px 0 4px 10px;border-radius: 4px;">
    <span style="color:#ff0000;text-shadow: none;font-weight: 500;">Find-IQ</span>
    <span style="color: #fff;background: #141414;padding: 4px 8px;border-radius: 4px;">Integration</span>
    ';

// Text
$_['text_extension'] = 'Расширения';
$_['text_success'] = 'Настройки успешно изменены!';
$_['text_edit'] = 'Настройки модуля';
$_['text_list'] = 'Журнал';
$_['entry_status'] = 'Статус';
$_['text_token'] = 'Токен';
$_['text_token_placeholder'] = 'Введите секретный токен сайта';
$_['text_full_reindex_timeout'] = 'Почасовой интервал обновления, если по тарифу он выше, то более частые обновления не будут иметь эффекта, это значение влияет на обновления товаров в системе FindIQ, а не напрямую в поисковом сервисе';
$_['text_full_reindex_timeout_placeholder'] = 'Рекомендуемые значения 8-48 для большинства магазинов';
$_['text_fast_reindex_timeout'] = 'Почасовой интервал быстрых обновлений (цены, акции, наличие), если по тарифу он выше, то более частые обновления не будут иметь эффекта, это значение влияет на обновления товаров в системе FindIQ, а не напрямую в поисковом сервисе';
$_['text_fast_reindex_timeout_placeholder'] = 'Рекомендуемые значения 1-8 для большинства магазинов';
$_['text_resize'] = 'Размеры изображений';
$_['text_resize_pattern'] = '%spx x %spx';
$_['text_image_processor'] = 'Обработка изображений';
$_['text_image_processor_gd'] = 'Встроенная (GD) — рекомендуется';
$_['text_image_processor_opencart'] = 'OpenCart (может требовать Imagick)';
$_['text_image_processor_help'] = 'Если синхронизация падает с ошибкой «Class Imagick not found» — используйте встроенную GD обработку.';
$_['text_docs'] = 'Информация и документация';

// Contacts block (Documentation tab)
$_['text_contacts_heading']   = 'Контакты и ресурсы';
$_['text_contacts_site']      = 'Сайт';
$_['text_contacts_admin']     = 'Админ панель';
$_['text_contacts_api_docs']  = 'API документация';
$_['text_contacts_write']     = 'Написать нам';
$_['text_contacts_telegram']  = 'Telegram канал';
$_['text_contacts_youtube']   = 'YouTube канал';
$_['text_contacts_github']    = 'GitHub';

// Webhook quick-links block (Documentation tab)
$_['text_webhook_heading']  = 'Быстрые URL вебхуков';
$_['text_webhook_help']     = 'Готовые к копированию URL с текущим секретным токеном.';
$_['text_webhook_start_full'] = 'Старт — полная заливка (новые + изменённые + переиндекс)';
$_['text_webhook_start_fast'] = 'Старт — быстрое обновление (только цены и наличие)';
$_['text_webhook_status']     = 'Статус';
$_['text_webhook_stop']       = 'Стоп';
$_['text_webhook_frontend'] = 'Обновить виджет (frontend)';
$_['text_webhook_copy']     = 'Скопировать';
$_['text_webhook_no_token'] = 'Задайте секретный токен в настройках и сохраните — здесь появятся готовые URL вебхуков.';
$_['text_version'] = 'Версия: %s';
$_['text_src'] = '<a href="%s">Исходный код</a>';
$_['text_new_version_available'] = 'Доступна новая версия модуля: <b>%s</b>. Текущая версия: <b>%s</b>. <a href="%s"><b>Скачать обновление</b></a>';
$_['text_version_is_actual'] = 'У вас установлена последняя версия модуля';
$_['text_current_version_is_corrupt'] = 'Ваша версия, вероятно, имеет отозванную версию, проверьте, есть ли у вас версия из <a href="%s"><b>нашего репозитория</b></a>, есть подозрение, что это не так';
$_['text_error_version_check'] = 'Ошибка проверки версии: ';
$_['text_error_version_check_current'] = 'Не удалось определить текущую версию модуля;';
$_['text_error_version_check_remote'] = 'Не удалось проверить новую версию через интернет; ';


// Error
$_['error_permission'] = 'У вас нет прав для управления этим модулем!';