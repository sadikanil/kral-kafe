<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Tüketim Ekle - {{ $location->name }} - Kral Kafe</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Styles -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    
    <style>
        body {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            min-height: 100vh;
        }
    </style>
</head>
<body>
    <div class="consume-page">
        <!-- Header -->
        <div class="consume-header">
            <div class="consume-location">
                @switch($location->type)
                    @case('shelf') 📚 @break
                    @case('cabinet') 🗄️ @break
                    @case('fridge') ❄️ @break
                @endswitch
                {{ $location->name }}
            </div>
            <h1 class="consume-title">Ne aldın?</h1>
            <p class="consume-subtitle">Ürün seç, adet belirle, kaydet!</p>
        </div>
        
        <!-- Ürün Listesi -->
        <div class="product-grid" id="productGrid">
            @forelse($products as $product)
                <div class="product-card" 
                     data-product-id="{{ $product->id }}"
                     data-product-name="{{ $product->name }}"
                     data-product-price="{{ $product->unit_price }}"
                     onclick="selectProduct(this)">
                    <div class="product-card-image">
                        @if($product->image_url)
                            <img src="{{ $product->image_src }}" alt="{{ $product->name }}" style="width: 100%; height: 100%; object-fit: cover; border-radius: var(--radius);">
                        @else
                            🍫
                        @endif
                    </div>
                    <div class="product-card-name">{{ $product->name }}</div>
                    <div class="product-card-price">{{ $product->formatted_price }}</div>
                    
                    <div class="quantity-selector" style="display: none;">
                        <button class="quantity-btn" onclick="event.stopPropagation(); changeQuantity(this, -1)">−</button>
                        <span class="quantity-value">1</span>
                        <button class="quantity-btn" onclick="event.stopPropagation(); changeQuantity(this, 1)">+</button>
                    </div>
                </div>
            @empty
                <div class="text-center p-4" style="grid-column: 1 / -1; color: white;">
                    Bu lokasyonda henüz ürün tanımlı değil.
                </div>
            @endforelse
        </div>
        
        <!-- Footer -->
        <div class="consume-footer" id="consumeFooter" style="display: none;">
            <div class="consume-summary">
                <div>
                    <div class="text-muted" style="font-size: 0.875rem;">Toplam</div>
                    <div class="consume-total" id="totalAmount">0,00 ₺</div>
                </div>
                <div style="text-align: right;">
                    <div class="text-muted" style="font-size: 0.875rem;" id="selectedCount">0 ürün</div>
                </div>
            </div>
            <button class="btn btn-primary consume-btn" onclick="submitConsumption()">
                ✓ Kaydet
            </button>
        </div>
        
        <!-- Undo Bar -->
        <div class="undo-bar" id="undoBar" style="display: none;">
            <div>
                <div class="undo-bar-message" id="undoMessage">Tüketim kaydedildi!</div>
                <div class="undo-bar-timer">
                    <div class="undo-bar-timer-fill" id="undoTimerFill"></div>
                </div>
            </div>
            <button class="undo-bar-btn" onclick="undoConsumption()">Geri Al</button>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <script>
        const locationId = {{ $location->id }};
        let selectedProducts = {};
        let lastConsumption = null;
        let undoTimeout = null;
        
        function selectProduct(element) {
            const productId = element.dataset.productId;
            const productName = element.dataset.productName;
            const productPrice = parseFloat(element.dataset.productPrice);
            
            if (selectedProducts[productId]) {
                // Deselect
                delete selectedProducts[productId];
                element.classList.remove('selected');
                element.querySelector('.quantity-selector').style.display = 'none';
            } else {
                // Select
                selectedProducts[productId] = {
                    id: productId,
                    name: productName,
                    price: productPrice,
                    quantity: 1
                };
                element.classList.add('selected');
                element.querySelector('.quantity-selector').style.display = 'flex';
                element.querySelector('.quantity-value').textContent = '1';
            }
            
            updateTotal();
        }
        
        function changeQuantity(button, delta) {
            const card = button.closest('.product-card');
            const productId = card.dataset.productId;
            const quantitySpan = card.querySelector('.quantity-value');
            
            let quantity = parseInt(quantitySpan.textContent) + delta;
            if (quantity < 1) quantity = 1;
            if (quantity > 10) quantity = 10;
            
            quantitySpan.textContent = quantity;
            
            if (selectedProducts[productId]) {
                selectedProducts[productId].quantity = quantity;
            }
            
            updateTotal();
        }
        
        function updateTotal() {
            let total = 0;
            let count = 0;
            
            for (const productId in selectedProducts) {
                const product = selectedProducts[productId];
                total += product.price * product.quantity;
                count += product.quantity;
            }
            
            document.getElementById('totalAmount').textContent = formatPrice(total);
            document.getElementById('selectedCount').textContent = count + ' ürün';
            
            const footer = document.getElementById('consumeFooter');
            footer.style.display = count > 0 ? 'block' : 'none';
        }
        
        function formatPrice(amount) {
            return amount.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
        }
        
        async function submitConsumption() {
            const products = Object.values(selectedProducts);
            if (products.length === 0) return;
            
            const submitBtn = document.querySelector('.consume-btn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Kaydediliyor...';
            
            try {
                // Prepare items payload
                const items = products.map(p => ({
                    product_id: p.id,
                    quantity: p.quantity
                }));

                const response = await fetch('{{ route("user.consume.store") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        location_id: locationId,
                        items: items
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    lastConsumption = data.consumption; // This now contains batch_ids
                    
                    // Reset selections
                    document.querySelectorAll('.product-card.selected').forEach(card => {
                        card.classList.remove('selected');
                        card.querySelector('.quantity-selector').style.display = 'none';
                    });
                    selectedProducts = {};
                    updateTotal();
                    
                    // Show undo bar
                    showUndoBar();
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message || 'Bir hata oluştu', 'error');
                }
                
            } catch (error) {
                console.error(error);
                showToast('Bağlantı hatası!', 'error');
            }
            
            submitBtn.disabled = false;
            submitBtn.innerHTML = '✓ Kaydet';
        }
        
        function showUndoBar() {
            const undoBar = document.getElementById('undoBar');
            const timerFill = document.getElementById('undoTimerFill');
            
            undoBar.style.display = 'flex';
            timerFill.style.animation = 'none';
            timerFill.offsetHeight; // Trigger reflow
            timerFill.style.animation = 'shrink 60s linear forwards';
            
            if (undoTimeout) clearTimeout(undoTimeout);
            undoTimeout = setTimeout(() => {
                undoBar.style.display = 'none';
                lastConsumption = null;
            }, 60000);
        }
        
        async function undoConsumption() {
            if (!lastConsumption || !lastConsumption.batch_ids || lastConsumption.batch_ids.length === 0) return;
            
            // Use the first ID for the route parameter, but send all IDs in body
            const firstId = lastConsumption.batch_ids[0];
            
            try {
                const response = await fetch(`/kullanici/tuketim/${firstId}/geri-al`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        ids: lastConsumption.batch_ids
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message || 'Geri alınamadı', 'error');
                }
            } catch (error) {
                showToast('Bağlantı hatası!', 'error');
            }
            
            document.getElementById('undoBar').style.display = 'none';
            if (undoTimeout) clearTimeout(undoTimeout);
            lastConsumption = null;
        }
        
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <span class="toast-message">${message}</span>
                <button class="toast-close" onclick="this.parentElement.remove()">✕</button>
            `;
            container.appendChild(toast);
            
            setTimeout(() => toast.remove(), 5000);
        }
    </script>
</body>
</html>
