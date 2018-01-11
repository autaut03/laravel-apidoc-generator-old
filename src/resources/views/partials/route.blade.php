<!-- START_{{$parsedRoute['id']}} -->
@if($parsedRoute['title'] != '')## {{ $parsedRoute['title']}}
@else## {{$parsedRoute['uri']}}
@endif
@if($parsedRoute['description'])

    {!! $parsedRoute['description'] !!}
@endif

> Example request:

```bash
curl -X {{$parsedRoute['methods'][0]}} "{{config('app.docs_url') ?: config('app.url')}}/{{$parsedRoute['uri']}}" \
-H "Accept: application/json"@if(count($parsedRoute['parameters'])) \
@foreach($parsedRoute['parameters'] as $attribute => $parameter)
    -d "{{$attribute}}"="" \
@endforeach
@endif

```

```javascript
$.ajax({
    crossDomain: true,
    url: "{{config('app.docs_url') ?: config('app.url')}}/{{$parsedRoute['uri']}}",
    method: "{{$parsedRoute['methods'][0]}}",
@if(count($parsedRoute['parameters']))
    data: {!! str_replace('    ','        ',json_encode(array_combine(array_keys($parsedRoute['parameters']), array_map(function($param){ return ''; },$parsedRoute['parameters'])), JSON_PRETTY_PRINT)) !!},
@endif
}).done(console.log);
```

> Responses:
@forelse($parsedRoute['responses'] as $response)
```json
@if(is_object($response) || is_array($response))
{!! json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) !!}
@elseif(is_string($response))
{!! $response !!}
@else
<!-- Even though it should never be called, I'll leave it there for now. -->
{!! json_encode(json_decode($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) !!}
@endif
```
@empty
> Never returns anything
@endforelse

### HTTP Request
@foreach($parsedRoute['methods'] as $method)
`{{$method}} {{$parsedRoute['uri']}}`

@endforeach

@include('apidoc::partials.parameters', ['title' => 'Path parameters', 'parameters' => $parsedRoute['parameters']['path']])
@include('apidoc::partials.parameters', ['title' => 'Query/Post parameters', 'parameters' => $parsedRoute['parameters']['query']])

<!-- END_{{$parsedRoute['id']}} -->
