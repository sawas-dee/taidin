// assets/js/app.js - Main JavaScript file

// Initialize when DOM ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('Lottery System Ready');
    
    // Initialize tooltips
    initTooltips();
    
    // Auto-hide alerts
    autoHideAlerts();
    
    // Number formatting
    formatNumberInputs();
    
    // Confirm deletes
    confirmDeleteActions();
});

// Initialize tooltips
function initTooltips() {
    const tooltips = document.querySelectorAll('[title]');
    tooltips.forEach(el => {
        el.style.cursor = 'help';
    });
}

// Auto hide alerts after 5 seconds
function autoHideAlerts() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.remove();
            }, 500);
        }, 5000);
    });
}

// Format number inputs
function formatNumberInputs() {
    const numberInputs = document.querySelectorAll('input[type="number"]');
    numberInputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.value && !isNaN(this.value)) {
                const step = this.getAttribute('step');
                if (step && step.includes('.')) {
                    const decimals = step.split('.')[1].length;
                    this.value = parseFloat(this.value).toFixed(decimals);
                }
            }
        });
    });
}

// Confirm delete actions
function confirmDeleteActions() {
    const deleteForms = document.querySelectorAll('form');
    deleteForms.forEach(form => {
        const deleteBtn = form.querySelector('button[type="submit"]');
        if (deleteBtn && deleteBtn.textContent.includes('ลบ')) {
            form.addEventListener('submit', function(e) {
                if (!deleteBtn.onclick) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'ยืนยันการลบ?',
                        text: 'การลบข้อมูลจะไม่สามารถย้อนกลับได้',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#ef4444',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: 'ลบ',
                        cancelButtonText: 'ยกเลิก'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    });
                }
            });
        }
    });
}

// Helper function to format number with commas
function numberWithCommas(x) {
    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// Helper function to parse number from formatted string
function parseNumber(str) {
    return parseFloat(str.replace(/,/g, ''));
}

// Show loading
function showLoading() {
    Swal.fire({
        title: 'กำลังประมวลผล...',
        allowOutsideClick: false,
        showConfirmButton: false,
        willOpen: () => {
            Swal.showLoading();
        }
    });
}

// Hide loading
function hideLoading() {
    Swal.close();
}

// AJAX helper
function ajaxRequest(url, data, callback) {
    showLoading();
    
    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams(data)
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (callback) callback(data);
    })
    .catch(error => {
        hideLoading();
        Swal.fire('Error', 'เกิดข้อผิดพลาด: ' + error, 'error');
    });
}

// Quick number input for lottery
function quickNumberInput(type, digits) {
    Swal.fire({
        title: `ใส่เลข ${digits} หลัก`,
        input: 'text',
        inputAttributes: {
            maxlength: digits,
            pattern: `\\d{${digits}}`,
            style: 'font-size: 2rem; text-align: center; font-weight: bold;'
        },
        inputValidator: (value) => {
            if (!value || value.length !== digits || !/^\d+$/.test(value)) {
                return `กรุณาใส่ตัวเลข ${digits} หลัก`;
            }
        },
        showCancelButton: true,
        confirmButtonText: 'ตกลง',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            return result.value;
        }
    });
}

// Print helper
function printElement(elementId) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>พิมพ์</title>
            <link rel="stylesheet" href="/assets/css/style.css">
            <style>
                body { padding: 20px; }
                @media print {
                    .no-print { display: none !important; }
                }
            </style>
        </head>
        <body>
            ${element.innerHTML}
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.focus();
    
    setTimeout(() => {
        printWindow.print();
    }, 500);
}

// Table to CSV export
function tableToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        cols.forEach(col => {
            // Remove any HTML and get text only
            const text = col.textContent.trim().replace(/"/g, '""');
            rowData.push(`"${text}"`);
        });
        
        csv.push(rowData.join(','));
    });
    
    // Download CSV
    const csvContent = '\ufeff' + csv.join('\n'); // BOM for UTF-8
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename || 'export.csv';
    link.click();
}

// Copy to clipboard
function copyToClipboard(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'คัดลอกแล้ว',
                timer: 1500,
                showConfirmButton: false
            });
        });
    } else {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        
        Swal.fire({
            icon: 'success',
            title: 'คัดลอกแล้ว',
            timer: 1500,
            showConfirmButton: false
        });
    }
}

// Debounce function for search/filter
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Filter table rows
function filterTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    
    if (!input || !table) return;
    
    const filter = debounce(() => {
        const searchTerm = input.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
        
        // Show "no results" if all hidden
        const visibleRows = table.querySelectorAll('tbody tr:not([style*="none"])');
        if (visibleRows.length === 0) {
            // Add no results row if not exists
            if (!table.querySelector('.no-results')) {
                const tbody = table.querySelector('tbody');
                const cols = table.querySelectorAll('thead th').length;
                const noResultsRow = document.createElement('tr');
                noResultsRow.className = 'no-results';
                noResultsRow.innerHTML = `<td colspan="${cols}" class="text-center text-muted">ไม่พบข้อมูล</td>`;
                tbody.appendChild(noResultsRow);
            }
        } else {
            // Remove no results row if exists
            const noResultsRow = table.querySelector('.no-results');
            if (noResultsRow) noResultsRow.remove();
        }
    }, 300);
    
    input.addEventListener('input', filter);
}

// Sort table
function sortTable(tableId, columnIndex) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    // Determine sort direction
    const th = table.querySelectorAll('thead th')[columnIndex];
    const isAscending = th.classList.contains('sort-asc');
    
    // Remove all sort classes
    table.querySelectorAll('thead th').forEach(header => {
        header.classList.remove('sort-asc', 'sort-desc');
    });
    
    // Add new sort class
    th.classList.add(isAscending ? 'sort-desc' : 'sort-asc');
    
    // Sort rows
    rows.sort((a, b) => {
        const aValue = a.cells[columnIndex].textContent.trim();
        const bValue = b.cells[columnIndex].textContent.trim();
        
        // Try to parse as number
        const aNum = parseFloat(aValue.replace(/[^0-9.-]/g, ''));
        const bNum = parseFloat(bValue.replace(/[^0-9.-]/g, ''));
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return isAscending ? bNum - aNum : aNum - bNum;
        }
        
        // Sort as string
        return isAscending 
            ? bValue.localeCompare(aValue) 
            : aValue.localeCompare(bValue);
    });
    
    // Re-append sorted rows
    rows.forEach(row => tbody.appendChild(row));
}

// Initialize sortable tables
function initSortableTables() {
    const tables = document.querySelectorAll('.sortable');
    
    tables.forEach(table => {
        const headers = table.querySelectorAll('thead th');
        
        headers.forEach((header, index) => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', () => {
                sortTable(table.id, index);
            });
        });
    });
}

// Dark mode toggle
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
}

// Check dark mode preference
if (localStorage.getItem('darkMode') === 'true') {
    document.body.classList.add('dark-mode');
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + K = Quick search
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        // Implement quick search
    }
    
    // Escape = Close modals
    if (e.key === 'Escape') {
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            modal.classList.remove('show');
        });
    }
});

// Export functions for global use
window.lotteryApp = {
    showLoading,
    hideLoading,
    ajaxRequest,
    numberWithCommas,
    parseNumber,
    quickNumberInput,
    printElement,
    tableToCSV,
    copyToClipboard,
    filterTable,
    sortTable,
    initSortableTables,
    toggleDarkMode
};