// Questions data
const questions = [
{
id: 1,
letter: 'H · Hurt',
text: 'Испытывает ли ваш питомец боль?',
description: 'Оцените, насколько животное страдает от боли. Учитывайте хромоту, напряжение, стоны, отказ от движения, изменения в поведении.'
},
{
id: 2,
letter: 'H · Hunger',
text: 'Ест ли ваш питомец?',
description: 'Оцените аппетит и способность есть самостоятельно. Если питомец не ест сам, приходится ли его кормить принудительно?'
},
{
id: 3,
letter: 'H · Hydration',
text: 'Пьет ли ваш питомец достаточно воды?',
description: 'Оцените, пьет ли животное достаточно. Обезвоживание — серьезный признак ухудшения состояния.'
},
{
id: 4,
letter: 'H · Hygiene',
text: 'Может ли питомец оставаться чистым?',
description: 'Оцените способность животного поддерживать чистоту. Есть ли проблемы с недержанием, диареей, невозможностью встать?'
},
{
id: 5,
letter: 'H · Happiness',
text: 'Испытывает ли питомец радость?',
description: 'Оцените эмоциональное состояние. Есть ли интерес к жизни, играм, общению? Или животное подавлено, изолировано?'
},
{
id: 6,
letter: 'M · Mobility',
text: 'Может ли питомец двигаться?',
description: 'Оцените способность двигаться самостоятельно. Может ли встать, дойти до миски, выйти на улицу?'
},
{
id: 7,
letter: 'M · More good days',
text: 'Хороших дней больше, чем плохих?',
description: 'Подумайте о последних неделях. Когда плохих дней становится больше, чем хороших, качество жизни серьезно снижено.'
}
];
let currentQuestion = 0;
let answers = new Array(7).fill(5);
function initTest() {
renderQuestion();
updateProgress();
}
function renderQuestion() {
const container = document.getElementById('questions-container');
const q = questions[currentQuestion];
container.innerHTML = `
<div class="question-letter">${q.letter}</div>
<h3 class="question-text">${q.text}</h3>
<p class="question-description">${q.description}</p>
<div class="slider-container">
<div class="slider-labels">
<span>Крайне плохо</span>
<span>Отлично</span>
</div>
<input type="range" min="0" max="10" value="${answers[currentQuestion]}"
class="slider" id="slider-${currentQuestion}"
oninput="updateSliderValue(${currentQuestion}, this.value)">
<div class="slider-value">
<span id="value-${currentQuestion}">${answers[currentQuestion]}</span>
<span>/10</span>
</div>
</div>
<div class="test-nav">
${currentQuestion > 0 ?
`<button class="btn btn-secondary" onclick="prevQuestion()">← Назад</button>` :
`<div></div>`
}
${currentQuestion < 6 ?
`<button class="btn btn-primary" onclick="nextQuestion()">Далее →</button>` :
`<button class="btn btn-primary" onclick="showResult()">Показать результат →</button>`
}
</div>
`;
}
function updateSliderValue(questionIndex, value) {
answers[questionIndex] = parseInt(value);
document.getElementById(`value-${questionIndex}`).textContent = value;
}
function nextQuestion() {
if (currentQuestion < 6) {
currentQuestion++;
renderQuestion();
updateProgress();
}
}
function prevQuestion() {
if (currentQuestion > 0) {
currentQuestion--;
renderQuestion();
updateProgress();
}
}
function updateProgress() {
const percent = Math.round((currentQuestion / 7) * 100);
document.getElementById('progress-fill').style.width = `${percent}%`;
document.getElementById('progress-label').textContent = `Вопрос ${currentQuestion + 1} из 7`;
document.getElementById('progress-percent').textContent = `${percent}%`;
}
function showResult() {
const total = answers.reduce((a, b) => a + b, 0);
const maxScore = 70;
document.getElementById('questions-container').style.display = 'none';
document.getElementById('progress-container').style.display = 'none';
const resultCard = document.getElementById('result-card');
resultCard.classList.add('active');
document.getElementById('result-score').innerHTML = `${total}<span>/${maxScore}</span>`;
let title, text, note;
if (total >= 50) {
title = 'Качество жизни остается хорошим';
text = 'Ваш питомец сохраняет достойное качество жизни. Продолжайте наблюдение и поддерживайте комфортные условия.';
note = 'Рекомендуется регулярное наблюдение у ветеринара. Поддерживайте привычный режим, комфортную обстановку и любимые занятия питомца. Повторите тест через 5-7 дней для отслеживания динамики.';
} else if (total >= 30) {
title = 'Есть области, требующие внимания';
text = 'Качество жизни снижено в некоторых аспектах. Это сигнал обсудить с ветеринаром возможности паллиативной помощи.';
note = 'Рекомендуется консультация с ветеринаром для оценки возможностей обезболивания, коррекции ухода или паллиативной поддержки. Повторите тест через 3-4 дня.';
} else {
title = 'Качество жизни значительно снижено';
text = 'Это серьезный сигнал. Ваш питомец испытывает значительные трудности, и важно обсудить дальнейшие действия со специалистом.';
note = 'Настоятельно рекомендуется срочная консультация с ветеринаром. Обсудите все возможные варианты помощи и поддержки. Специалисты 103vet готовы провести деликатный осмотр на дому.';
}
document.getElementById('result-title').textContent = title;
document.getElementById('result-text').textContent = text;
document.getElementById('result-note').textContent = note;
resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function restartTest() {
currentQuestion = 0;
answers = new Array(7).fill(5);
document.getElementById('questions-container').style.display = 'block';
document.getElementById('progress-container').style.display = 'block';
document.getElementById('result-card').classList.remove('active');
renderQuestion();
updateProgress();
window.scrollTo({ top: document.getElementById('test').offsetTop - 100, behavior: 'smooth' });
}