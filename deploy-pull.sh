#!/bin/bash
# Запускает git pull во всех блогах на сервере.
# Использование: ./deploy-pull.sh
# Требует: sshpass  →  brew install sshpass

# ─── Настройки ────────────────────────────────────────────────────────────────
SSH_HOST="ssh.beget.com"     # хост SSH на Beget
SSH_USER="your_username"     # логин Beget
SSH_PORT=22

# Папки блогов на сервере (добавляй новые сюда)
BLOGS=(
    "/home/${SSH_USER}/blog.kkr.ru/aekuznetsov"
    "/home/${SSH_USER}/blog.kkr.ru/cityguide"
)
# ──────────────────────────────────────────────────────────────────────────────

# Читаем пароль (не показываем в истории команд)
if [ -z "$SSH_PASS" ]; then
    read -s -p "SSH пароль для ${SSH_USER}@${SSH_HOST}: " SSH_PASS
    echo
fi

if ! command -v sshpass &> /dev/null; then
    echo "Ошибка: sshpass не установлен. Запусти: brew install sshpass"
    exit 1
fi

# Формируем команды git pull для всех блогов
REMOTE_CMD=""
for DIR in "${BLOGS[@]}"; do
    REMOTE_CMD+="echo '--- ${DIR} ---'; git -C '${DIR}' pull; "
done

echo "Подключаюсь к ${SSH_USER}@${SSH_HOST}..."
sshpass -p "$SSH_PASS" ssh -p "$SSH_PORT" \
    -o StrictHostKeyChecking=no \
    -o ConnectTimeout=15 \
    "${SSH_USER}@${SSH_HOST}" \
    "$REMOTE_CMD"

echo
echo "Готово."
