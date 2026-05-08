<h1>Add Payment</h1>

<p>
    Customer: {{ $rental->customer_name }} <br>
    Product: {{ $rental->product->name ?? 'N/A' }} <br>
    Rental ID: {{ $rental->id }}
</p>

@if ($errors->any())
    <div style="color:red;">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="/rentals/{{ $rental->id }}/payments">
    @csrf

    <p>
        Payment Date:<br>
        <input type="date" name="payment_date" required>
    </p>

    <p>
        Amount:<br>
        <input type="number" name="amount" step="0.01" required>
    </p>

    <p>
        Payment Method:<br>
        <select name="payment_method">
            <option value="">Select</option>
            <option value="cash">Cash</option>
            <option value="upi">UPI</option>
            <option value="bank">Bank Transfer</option>
            <option value="card">Card</option>
        </select>
    </p>

    <p>
        Notes:<br>
        <textarea name="notes"></textarea>
    </p>

    <button type="submit">Save Payment</button>
</form>

<p>
    <a href="/rentals">Back to Rentals</a>
</p>