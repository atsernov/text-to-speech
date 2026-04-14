<template>
    <div class="tts-container">
        <h2>Озвучка эстонского текста</h2>

        <textarea
            v-model="inputText"
            placeholder="Sisesta tekst siia... (Введите текст здесь)"
            rows="8"
        ></textarea>

        <button @click="sendText" :disabled="!inputText.trim()">
            Озвучить
        </button>
    </div>
</template>

<script setup>
import { ref } from 'vue';

// Создаем реактивную переменную для хранения текста
const inputText = ref('');

// Функция, которая срабатывает по клику на кнопку
const sendText = () => {
    // Проверяем, не пустое ли поле
    if (!inputText.value.trim()) {
        return;
    }

    // Пока что просто выводим текст в консоль (наша заглушка)
    console.log('Готовим к отправке на Laravel API:', inputText.value);

    // В будущем здесь будет код отправки через axios
};
</script>

<style scoped>
/* Базовые стили, чтобы всё выглядело аккуратно */
.tts-container {
    max-width: 600px;
    margin: 40px auto;
    padding: 20px;
    font-family: sans-serif;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    background-color: #ffffff;
}

h2 {
    text-align: center;
    color: #333;
}

textarea {
    width: 100%;
    padding: 12px;
    margin-bottom: 15px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 16px;
    resize: vertical; /* Позволяет пользователю менять высоту поля */
    box-sizing: border-box;
}

button {
    width: 100%;
    padding: 12px;
    background-color: #4CAF50;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
    transition: background-color 0.3s;
}

button:hover {
    background-color: #45a049;
}

button:disabled {
    background-color: #cccccc;
    cursor: not-allowed;
}
</style>
