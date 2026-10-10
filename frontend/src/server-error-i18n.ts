const errors: Record<string, [string, string, string]> = {
  invalid_revision: [
    "版本无效，请重新打开历史。",
    "Invalid version. Reopen history.",
    "Неправильна версія. Відкрийте історію знову.",
  ],
  revision_unavailable: [
    "历史版本不存在或不可恢复。",
    "This revision is unavailable.",
    "Ця версія недоступна.",
  ],
  idempotency_conflict: [
    "此请求标识已用于不同内容，请重新操作。",
    "This request ID was used for different content. Retry the action.",
    "Цей ідентифікатор запиту вже використано для іншого вмісту. Повторіть дію.",
  ],
  share_unavailable: [
    "课堂分享已关闭或不可用。",
    "Lesson sharing is unavailable.",
    "Посилання заняття недоступне.",
  ],
  import_row_limit: [
    "文件最多允许 {max} 行数据。",
    "The file allows at most {max} data rows.",
    "Файл може містити не більше {max} рядків даних.",
  ],
  import_column_limit: [
    "表格列数超过限制。",
    "The spreadsheet has too many columns.",
    "Таблиця має забагато стовпців.",
  ],
  import_xlsx_invalid: [
    "无法读取有效 XLSX 文件。",
    "Cannot read a valid XLSX file.",
    "Не вдалося прочитати правильний файл XLSX.",
  ],
  import_no_rows: [
    "没有可导入的数据行。",
    "There are no data rows to import.",
    "Немає рядків для імпорту.",
  ],
  import_header_duplicate: [
    "姓名或学号表头重复。",
    "Duplicate name or student-ID header.",
    "Повторний заголовок імені або номера студента.",
  ],
  import_headers_missing: [
    "缺少姓名或学号列，请使用下载的模板。",
    "Missing name or student-ID column. Use the downloaded template.",
    "Відсутній стовпець імені або номера студента. Використайте завантажений шаблон.",
  ],
  import_file_size: [
    "文件不存在或大于 4 MB。",
    "The file is missing or larger than 4 MB.",
    "Файл відсутній або перевищує 4 МБ.",
  ],
  import_zip_limits: [
    "文件压缩内容超过安全大小限制。",
    "Compressed file content exceeds size limits.",
    "Стиснений вміст файлу перевищує ліміт розміру.",
  ],
  import_active_content: [
    "文件包含不允许的宏、外部引用或活动内容。",
    "The file contains unsupported macros, external references or active content.",
    "Файл містить неприпустимі макроси, зовнішні посилання або активний вміст.",
  ],
  import_single_sheet_required: [
    "请使用只有一张工作表的导入文件。",
    "Use an import file with one worksheet.",
    "Використайте файл імпорту з одним аркушем.",
  ],
  import_policy_invalid: [
    "重复学号处理方式无效。",
    "Invalid duplicate-ID policy.",
    "Неправильне правило повторних номерів.",
  ],
  import_unavailable: [
    "导入记录不存在或无权访问。",
    "The import is unavailable or inaccessible.",
    "Імпорт відсутній або недоступний.",
  ],
  import_preview_changed: [
    "预检结果已变化，请重新预检。",
    "The preview changed. Validate again.",
    "Перегляд змінився. Перевірте знову.",
  ],
  import_formula_not_allowed: [
    "此行包含公式，不能导入。",
    "This row contains a formula and cannot be imported.",
    "Цей рядок містить формулу й не може бути імпортований.",
  ],
  import_name_invalid: [
    "姓名不能为空、过长或包含控制字符。",
    "Name is empty, too long or contains control characters.",
    "Ім’я порожнє, задовге або містить керувальні символи.",
  ],
  import_student_id_must_be_text: [
    "学号须为文本，无法猜测已经丢失的前导零。",
    "Student ID must be text. Lost leading zeros cannot be guessed.",
    "Номер має бути текстом. Втрачені початкові нулі неможливо вгадати.",
  ],
  import_student_id_invalid: [
    "学号须至少 {min} 个字符且不能包含空白。",
    "Student ID needs at least {min} characters without whitespace.",
    "Номер має містити щонайменше {min} символів без пробілів.",
  ],
  import_duplicate_in_file: [
    "文件内学号重复，首次出现在第 {first_row} 行。",
    "Duplicate ID in the file; first appears on row {first_row}.",
    "Повтор номера у файлі; вперше в рядку {first_row}.",
  ],
  student_id_exists: [
    "此学号已有账户。",
    "This student ID already has an account.",
    "Обліковий запис із цим номером уже існує.",
  ],
  user_unavailable: [
    "用户不存在或不可管理。",
    "This user is unavailable.",
    "Користувач недоступний.",
  ],
  last_admin_required: [
    "必须至少保留一名启用的管理员。",
    "Keep at least one enabled administrator.",
    "Потрібен хоча б один увімкнений адміністратор.",
  ],
  course_has_active_lesson: [
    "课程还有未结束课堂，请先结束课堂。",
    "This course has an unfinished lesson. End it first.",
    "Курс має незавершене заняття. Спочатку завершіть його.",
  ],
  invalid_audio_frame: [
    "音频包格式不正确，已停止收音。",
    "Invalid audio frame. Capture stopped.",
    "Неправильний аудіопакет. Запис зупинено.",
  ],
  audio_sequence_conflict: [
    "音频序号内容冲突，收音已停止。",
    "Audio sequence content conflicts. Capture stopped.",
    "Конфлікт вмісту аудіопослідовності. Запис зупинено.",
  ],
  origin_rejected: [
    "连接来源不被服务器允许。",
    "This connection origin is not allowed.",
    "Сервер не дозволяє джерело з’єднання.",
  ],
  message_too_large: [
    "消息超过服务器大小限制。",
    "Message exceeds the server size limit.",
    "Повідомлення перевищує ліміт сервера.",
  ],
  subscribe_required: [
    "请先连接到获授权课堂。",
    "Connect to an authorized lesson first.",
    "Спочатку під’єднайтеся до дозволеного заняття.",
  ],
  already_subscribed: [
    "此连接已经订阅课堂。",
    "This connection already subscribed to a lesson.",
    "Це з’єднання вже підписане на заняття.",
  ],
  confirmation_required: [
    "此操作需要明确确认。",
    "This action needs explicit confirmation.",
    "Ця дія потребує явного підтвердження.",
  ],
  unknown_command: [
    "服务器不支持此操作。",
    "The server does not support this command.",
    "Сервер не підтримує цю команду.",
  ],
  invalid_state: [
    "当前课堂状态不允许此操作。",
    "The current lesson state does not allow this action.",
    "Поточний стан заняття не дозволяє цю дію.",
  ],
  translation_not_ready: [
    "模型连接尚未准备完成。",
    "The model connection is not ready.",
    "З’єднання моделі ще не готове.",
  ],
  audio_rate_limited: [
    "音频发送超过速率限制，已暂停。",
    "Audio exceeded the rate limit. Capture paused.",
    "Аудіо перевищило ліміт швидкості. Запис призупинено.",
  ],
  handoff_target_offline: [
    "目标设备已离线，请重新选择。",
    "The recipient device is offline. Choose again.",
    "Пристрій отримувача офлайн. Оберіть інший.",
  ],
  gateway_lock_lost: [
    "实时网关租约失效，收音已停止。",
    "The gateway lease was lost. Capture stopped.",
    "Втрачено оренду шлюзу. Запис зупинено.",
  ],
  handoff_same_device: [
    "不能将录音权转移到当前设备。",
    "Cannot transfer recording to this device.",
    "Неможливо передати право цьому самому пристрою.",
  ],
  handoff_limit: [
    "已有过多待处理交接，请先处理或取消。",
    "Too many pending handoffs. Handle or cancel them first.",
    "Забагато передач у черзі. Спочатку опрацюйте або скасуйте їх.",
  ],
  handoff_stale: [
    "交接已失效，课堂收音者或状态已变化。",
    "The handoff expired because recorder or lesson state changed.",
    "Передача втратила чинність через зміну оператора або стану заняття.",
  ],
  recorder_recovery_window: [
    "收音者仍在断线恢复宽限期，请等待。",
    "The recorder is within the reconnect grace period. Wait.",
    "Оператор ще в пільговому періоді відновлення. Зачекайте.",
  ],
};
export const serverErrorMessages = Object.fromEntries(
  ["zh-CN", "en", "uk"].map((language, index) => [
    language,
    Object.fromEntries(
      Object.entries(errors).map(([key, text]) => [
        `error_${key}`,
        text[index],
      ]),
    ),
  ]),
) as Record<"zh-CN" | "en" | "uk", Record<string, string>>;
