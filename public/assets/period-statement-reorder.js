(function () {
  'use strict';

  var tabela = document.querySelector('table[data-reorder-url]');
  var csrf = document.querySelector('meta[name="csrf-token"]');
  if (!tabela || !csrf) return;

  var reorderUrl = tabela.dataset.reorderUrl;
  var arrastando = null;

  tabela.querySelectorAll('tr[data-line-id]').forEach(function (linha) {
    linha.addEventListener('dragstart', function () {
      arrastando = linha;
      linha.classList.add('dragging');
    });

    linha.addEventListener('dragover', function (evento) {
      if (!arrastando || arrastando === linha || arrastando.dataset.day !== linha.dataset.day) {
        return;
      }
      evento.preventDefault();

      var caixa = linha.getBoundingClientRect();
      var antes = (evento.clientY - caixa.top) < caixa.height / 2;
      var novoProximo = antes ? linha : linha.nextSibling;

      // Só mexe no DOM quando a posição realmente muda — mover a cada pixel
      // de tremida do mouse é o que fazia a borda vermelha piscar.
      if (arrastando.nextSibling === novoProximo) {
        return;
      }

      // Direção pela posição relativa no DOM (arrastando já estava antes ou
      // depois de onde vai entrar), não pelo Y bruto do evento — o Y sozinho
      // varia a cada pixel e piscava a cor pra cima/baixo sem sentido.
      var arrastandoEstavaAntes = !!(linha.compareDocumentPosition(arrastando) & Node.DOCUMENT_POSITION_PRECEDING);
      arrastando.classList.toggle('descendo', arrastandoEstavaAntes);
      arrastando.classList.toggle('subindo', !arrastandoEstavaAntes);

      linha.parentNode.insertBefore(arrastando, novoProximo);
    });

    linha.addEventListener('dragend', function () {
      if (!arrastando) return;

      var dia = arrastando.dataset.day;
      arrastando.classList.remove('dragging', 'subindo', 'descendo');
      arrastando = null;

      salvarOrdemDoDia(dia);
    });
  });

  function salvarOrdemDoDia(dia) {
    var idsOrdenados = Array.prototype.map.call(
      tabela.querySelectorAll('tr[data-day="' + dia + '"]'),
      function (linha) { return parseInt(linha.dataset.lineId, 10); },
    );

    fetch(reorderUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf.content,
      },
      body: JSON.stringify({ movement_date: dia, line_ids: idsOrdenados }),
    })
      .then(function (resposta) {
        if (!resposta.ok) {
          return resposta.json().then(function (corpo) {
            throw new Error(corpo.message || 'Falha ao salvar a ordem.');
          });
        }
        return resposta.json();
      })
      .then(function () {
        // Saldo corrido de todas as linhas do dia muda com a ordem — mais
        // simples e mais seguro recarregar do que tentar remendar as células
        // no DOM.
        window.location.reload();
      })
      .catch(function (erro) {
        alert(erro.message || 'Falha ao salvar a ordem.');
        window.location.reload();
      });
  }
})();
