// فتح وإغلاق Modal
const modal = document.getElementById('imageModal');
const modalImage = document.getElementById('modalImage');
const modalTitle = document.getElementById('modalTitle');
const closeBtn = document.querySelector('.close');

// إضافة event listeners للدوائر الملونة
document.addEventListener('DOMContentLoaded', function() {
    const colorDots = document.querySelectorAll('.color-dot');
    
    colorDots.forEach(dot => {
        dot.addEventListener('click', function() {
            const imageUrl = this.getAttribute('data-image');
            const trainer = this.getAttribute('data-trainer');
            const time = this.getAttribute('data-time');
            const date = this.getAttribute('data-date');
            const gender = this.classList.contains('men-dot') ? 'رجال' : 'نساء';
            
            if (imageUrl && imageUrl !== 'images/') {
                modalImage.src = imageUrl;
                modalTitle.textContent = `${gender} - ${trainer} - ${time} - ${date}`;
                modal.style.display = 'block';
            } else {
                alert('لا توجد صورة متاحة لهذه الجلسة');
            }
        });
    });
    
    // إغلاق Modal عند الضغط على X
    closeBtn.addEventListener('click', function() {
        modal.style.display = 'none';
    });
    
    // إغلاق Modal عند الضغط خارج الصورة
    window.addEventListener('click', function(event) {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
    
    // إغلاق Modal بمفتاح ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            modal.style.display = 'none';
        }
    });
});

