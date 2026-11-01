@if($errors->any())
    <div class="p-4 rounded-xl text-sm text-white" style="background:#EE1C25;">
        @foreach($errors->all() as $e) <p>• {{ $e }}</p> @endforeach
    </div>
@endif
