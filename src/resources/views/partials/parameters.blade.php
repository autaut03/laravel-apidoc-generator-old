@if(count($parameters))
### {{ $title }}

Parameter | Type | Required | Description | Rules
--------- | ------- | ------- | ------- | ----------- | ----------------
@foreach($parameters as $parameter)
{{ $parameter['name'] }} | {{$parameter['type']}} | @if($parameter['required']) true @else false @endif | {{ $parameter['description'] }} | {{ implode(", ", $parameter['rules']) }}
@endforeach
@endif