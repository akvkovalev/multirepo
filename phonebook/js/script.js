// Объявляем глобальные переменные в начале
let adminMode = false;

// Все функции объявляем глобально
function toggleTheme() {
    document.body.classList.toggle('dark');
    const isDark = document.body.classList.contains('dark');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');

    const icon = document.getElementById('themeBtn');
    icon.textContent = isDark ? '🌙 Тема' : '🌞 Тема';
}

function filterContacts() {
    const input = document.getElementById("searchInput").value.toLowerCase();
    const tables = document.querySelectorAll("table");

    // Обновляем видимость крестика
    updateClearButton();

    tables.forEach(table => {
        const rows = Array.from(table.querySelectorAll("tr:not(:first-child)"));
        const matched = [];
        const unmatched = [];

        rows.forEach(row => {
            const cells = Array.from(row.querySelectorAll("td"));
            // Исключаем последнюю ячейку с действиями из поиска
            const hasActions = cells.length > 5; // Если есть колонка действий
            const searchCells = hasActions ? cells.slice(0, -1) : cells;
            let rowText = "";
            let isMatch = false;

            searchCells.forEach(cell => {
                const originalText = cell.textContent;
                const lower = originalText.toLowerCase();

                if (input && lower.includes(input)) {
                    const regex = new RegExp(`(${input})`, 'gi');
                    cell.innerHTML = originalText.replace(regex, `<mark>$1</mark>`);
                    isMatch = true;
                } else {
                    cell.innerHTML = originalText;
                }

                rowText += lower + " ";
            });

            if (!input || isMatch || rowText.includes(input)) {
                matched.push(row);
            } else {
                unmatched.push(row);
            }
        });

        matched.forEach(row => {
            row.style.display = "";
            table.appendChild(row);
        });

        unmatched.forEach(row => {
            row.style.display = "none";
            table.appendChild(row);
        });
    });
}

function exportToExcel() {
    const table = document.getElementById("contactsTable");
    const html = `
        <html xmlns:o="urn:schemas-microsoft-com:office:office" 
              xmlns:x="urn:schemas-microsoft-com:office:excel" 
              xmlns="http://www.w3.org/TR/REC-html40">
        <head><!--[if gte mso 9]>
        <xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
        <x:Name>Справочник</x:Name>
        <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
        </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>
        <![endif]--></head>
        <body>${table.outerHTML}</body></html>`;

    const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');
    link.href = url;
    link.download = 'справочник.xls';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function showPinForm() {
    document.getElementById('pinForm').style.display = 'flex';
    document.querySelector('#pinForm input[name="pin_code"]').focus();
}

function hidePinForm() {
    document.getElementById('pinForm').style.display = 'none';
}

function showAddForm() {
    document.getElementById('formTitle').textContent = 'Добавить сотрудника';
    document.getElementById('formId').value = '';
    document.getElementById('formName').value = '';
    document.getElementById('formEmail').value = '';
    document.getElementById('formDepartment').value = '';
    document.getElementById('formTitleInput').value = '';
    document.getElementById('formExtension').value = '';
    document.getElementById('formAction').value = 'add';
    
    document.getElementById('employeeForm').style.display = 'flex';
}

function editEmployee(id, name, email, department, title, extension) {
    document.getElementById('formTitle').textContent = 'Редактировать сотрудника';
    document.getElementById('formId').value = id;
    document.getElementById('formName').value = name;
    document.getElementById('formEmail').value = email;
    document.getElementById('formDepartment').value = department;
    document.getElementById('formTitleInput').value = title;
    document.getElementById('formExtension').value = extension;
    document.getElementById('formAction').value = 'edit';
    
    document.getElementById('employeeForm').style.display = 'flex';
}

function deleteEmployee(id, name) {
    if (confirm(`Вы уверены, что хотите удалить сотрудника "${name}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function hideForm() {
    document.getElementById('employeeForm').style.display = 'none';
}

function showImportForm() {
    document.getElementById('importForm').style.display = 'flex';
}

function hideImportForm() {
    document.getElementById('importForm').style.display = 'none';
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    // Восстановление темы
    const input = document.getElementById('searchInput');
    if (input && input.classList.contains('hidden')) {
        input.classList.remove('hidden');
    }
    
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
        document.body.classList.add('dark');
        document.getElementById('themeBtn').textContent = '🌙 Тема';
    }
	
	 // Инициализация крестика очистки
    updateClearButton();
	
	// Обработчик для поля поиска
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', updateClearButton);
        
        // Очистка по Escape
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                clearSearch();
            }
        });
    }
    // Инициализация сортировки таблицы
    const table = document.getElementById('contactsTable');
    if (table) {
        const headers = table.querySelectorAll('th');
        let sortDirection = {};

        headers.forEach((header, index) => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', () => {
                const rows = Array.from(table.querySelectorAll('tr:nth-child(n+2)'));
                const isNumeric = !isNaN(rows[0].children[index].textContent.trim());
                const direction = sortDirection[index] = -(sortDirection[index] || -1);

                headers.forEach((h, i) => {
                    h.textContent = h.dataset.title;
                    if (i !== index) sortDirection[i] = 0;
                });

                const arrow = direction === 1 ? ' ▲' : ' ▼';
                header.textContent = header.dataset.title + arrow;

                rows.sort((a, b) => {
                    const aText = a.children[index].textContent.trim();
                    const bText = b.children[index].textContent.trim();
                    return isNumeric
                        ? direction * (parseFloat(aText) - parseFloat(bText))
                        : direction * aText.localeCompare(bText, 'ru', { sensitivity: 'base' });
                });

                rows.forEach(row => table.appendChild(row));
            });
        });
    }

    // Закрытие модальных окон по клику вне их
    document.addEventListener('click', function(e) {
        const modal = document.getElementById('employeeForm');
        const importModal = document.getElementById('importForm');
        const pinModal = document.getElementById('pinForm');
        
        if (e.target === modal) {
            hideForm();
        }
        if (e.target === importModal) {
            hideImportForm();
        }
        if (e.target === pinModal) {
            hidePinForm();
        }
    });

    // Фокус на поле пин-кода при открытии формы
    const pinForm = document.getElementById('pinForm');
    if (pinForm) {
        pinForm.addEventListener('shown', function() {
            const pinInput = this.querySelector('input[name="pin_code"]');
            if (pinInput) pinInput.focus();
        });
    }
});

// Функция очистки поиска
function clearSearch() {
    const searchInput = document.getElementById('searchInput');
    searchInput.value = '';
    filterContacts(); 
    updateClearButton(); 
    searchInput.focus(); 
}

// Функция обновления видимости крестика
function updateClearButton() {
    const searchInput = document.getElementById('searchInput');
    const clearButton = document.querySelector('.clear-search');
    
    if (searchInput.value.length > 0) {
        clearButton.style.display = 'block';
    } else {
        clearButton.style.display = 'none';
    }
}
